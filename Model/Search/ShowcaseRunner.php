<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Framework\App\Cache\Type\Block;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\FlagManager;
use Magento\PageCache\Model\Cache\Type as PageCache;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Api\HttpClient;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Sync\SyncCompletion;

/**
 * Asks Quissly for a website's search bar suggestions, in the background (Cron/ShowcaseCron),
 * and keeps track of when to ask - the Quissly Shopify app's showcase runner (the WooCommerce
 * and CS-Cart plugins have the same).
 *
 * Quissly makes the suggestions (`POST /v2beta/qsearch/suggestions_enrich`, SuggestionsEnrich):
 * it writes them in each language, checks each one finds products, and writes them into the
 * QSearch widget_config itself - keeping any list the merchant edited, so nothing here needs
 * to know about the merchant's own lists. What this sends is what only Magento knows: the
 * website's store-view languages (the main one first, at most SuggestionsEnrich::MAX_LANGUAGES)
 * and its catalog as each store view shows it (ShowcaseCatalog).
 *
 * When: after the first catalog sync (before it there is nothing to search). Right after the
 * sync the index can still be filling - Quissly answers "insufficient" and this asks again in
 * an hour; after MAX_ATTEMPTS it accepts a shorter list. Done once Quissly answers written or
 * unchanged, until a store-view language is added (its list is then asked for) or the
 * generator changes (lists from an older one are made again once).
 */
class ShowcaseRunner
{
    public const FLAG_PREFIX = 'quissly_showcase_w';
    private const LOCK_SECONDS = 600;
    private const HOUR = 3600;

    /** One call can take minutes: a model call and up to 12 searches per language. */
    private const TIMEOUT_SECONDS = 180;

    /**
     * @param SuggestionsEnrich $enrich
     * @param ShowcaseCatalog $catalog
     * @param HttpClient $http
     * @param SearchSuggestions $suggestions
     * @param SyncCompletion $completion
     * @param Settings $settings
     * @param FlagManager $flags
     * @param TypeListInterface $cacheTypeList
     * @param LoggerInterface $logger
     * @param SuggestionLanguage $languages
     */
    public function __construct(
        private readonly SuggestionsEnrich $enrich,
        private readonly ShowcaseCatalog $catalog,
        private readonly HttpClient $http,
        private readonly SearchSuggestions $suggestions,
        private readonly SyncCompletion $completion,
        private readonly Settings $settings,
        private readonly FlagManager $flags,
        private readonly TypeListInterface $cacheTypeList,
        private readonly LoggerInterface $logger,
        private readonly SuggestionLanguage $languages
    ) {
    }

    /**
     * The website's suggestion state.
     *
     * Keys: generated_at, languages_done, generator, generated, generated_languages, attempts,
     * next_at, locked_at, last_error, last_status.
     *
     * @param int $websiteId
     * @return array
     */
    public function state(int $websiteId): array
    {
        $state = $this->flags->getFlagData(self::FLAG_PREFIX . $websiteId);
        return is_array($state) ? $state : [];
    }

    /**
     * Quissly has made this list (the main one, or one language's).
     *
     * @param int $websiteId
     * @param string|null $language another language's list; null = the main list
     * @return bool
     */
    public function finished(int $websiteId, ?string $language = null): bool
    {
        $state = $this->state($websiteId);
        return $language === null
            ? !empty($state['generated_at'])
            : in_array($language, (array)($state['languages_done'] ?? []), true);
    }

    /**
     * What Quissly generated last, as it answered here ([] when nothing yet).
     *
     * Configuration prefers Quissly's own copy (client_specific_queries_generated); this one
     * covers a service Quissly has not written it to.
     *
     * @param int $websiteId
     * @param string|null $language another language's list; null = the main list
     * @return string[]
     */
    public function generated(int $websiteId, ?string $language = null): array
    {
        $state = $this->state($websiteId);
        return $this->suggestions->clean($language === null
            ? ($state['generated'] ?? [])
            : ($state['generated_languages'][$language] ?? []));
    }

    /**
     * The cron tick for one website: ask Quissly when due. Never throws.
     *
     * @param int $websiteId
     * @param int|null $now
     * @return string what happened
     */
    public function tick(int $websiteId, ?int $now = null): string
    {
        $now = $now ?? time();
        if (!$this->settings->isConfigured($websiteId)
            || !$this->completion->isComplete($websiteId)
            || $this->suggestions->serviceId($websiteId) === null
        ) {
            return 'not_ready';
        }
        $stores = $this->stores($websiteId);
        $state = $this->state($websiteId);
        if (!$this->due($state, array_keys($stores))) {
            return 'finished';
        }
        if ((int)($state['next_at'] ?? 0) > $now) {
            return 'not_due';
        }
        if ((int)($state['locked_at'] ?? 0) > $now - self::LOCK_SECONDS) {
            return 'locked';
        }
        $this->update($websiteId, ['locked_at' => $now]);

        try {
            return $this->ask($websiteId, $stores, $state, $now);
        } catch (\Throwable $e) {
            return $this->retry($websiteId, $now, self::HOUR, substr($e->getMessage(), 0, 300), 'error');
        }
    }

    /**
     * One call, end to end.
     *
     * @param int $websiteId
     * @param array $stores language => store view (null = the website's default)
     * @param array $state
     * @param int $now
     * @return string
     */
    private function ask(int $websiteId, array $stores, array $state, int $now): string
    {
        $catalogs = [];
        foreach ($stores as $language => $storeId) {
            $catalogs[$language] = $this->catalog->facts($websiteId, $storeId);
        }
        $attempts = (int)($state['attempts'] ?? 0) + 1;
        $regenerate = !empty($state['generated_at']) && ($state['generator'] ?? '') !== SuggestionsEnrich::GENERATOR;
        $body = $this->enrich->body(
            (string)array_key_first($stores),
            array_keys($stores),
            $catalogs,
            ['main' => $this->generated($websiteId), 'by_language' => $this->previousLanguages($state)],
            $regenerate ? 'regenerate' : 'fill',
            $attempts >= SuggestionsEnrich::MAX_ATTEMPTS
        );

        $response = $this->http->postV2(
            HttpClient::PATH_SUGGESTIONS_ENRICH,
            $body,
            (string)$this->settings->apiToken($websiteId),
            (string)$this->settings->privateKeyPem($websiteId),
            $this->settings->environment($websiteId),
            false,
            self::TIMEOUT_SECONDS,
            $this->settings->apiBaseUrl($websiteId)
        );
        $answer = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($answer)) {
            $error = sprintf('suggestions_enrich http=%d', $response['status']);
            $this->logger->info('[quissly] ' . $error . ' website=' . $websiteId);
            return $this->retry($websiteId, $now, self::HOUR, $error, 'error');
        }

        $status = (string)($answer['status'] ?? '');
        $next = $this->enrich->schedule($status);
        if (!$next['done']) {
            return $this->retry($websiteId, $now, $next['retry'], $next['reason'], $status !== '' ? $status : 'error');
        }

        $lists = [];
        foreach (array_keys($stores) as $language) {
            $lists[$language] = $this->suggestions->clean($answer['languages'][$language] ?? []);
        }
        $main = $this->suggestions->clean($answer['main'] ?? []);
        $this->update($websiteId, [
            'generated_at' => (int)($state['generated_at'] ?? 0) ?: $now,
            'generator' => SuggestionsEnrich::GENERATOR,
            'languages_done' => array_keys($stores),
            'generated' => $main !== [] ? $main : ($state['generated'] ?? []),
            'generated_languages' => $lists,
            'attempts' => 0,
            'next_at' => 0,
            'locked_at' => 0,
            'last_error' => '',
            'last_status' => $status,
        ]);
        if ($status === 'written') {
            // The overlay's list is part of every cached page (as Config/Backend/SearchSuggestions).
            $this->cacheTypeList->cleanType(PageCache::TYPE_IDENTIFIER);
            $this->cacheTypeList->cleanType(Block::TYPE_IDENTIFIER);
        }
        $this->logger->info(sprintf(
            '[quissly] search bar suggestions %s website=%d languages=%s',
            $status,
            $websiteId,
            implode(',', array_keys($stores))
        ));
        return $status;
    }

    /**
     * The other languages' lists generated before, for Quissly's merchant-edit baseline.
     *
     * Older versions generated them here, one entry per language under `languages`.
     *
     * @param array $state
     * @return array<string, string[]>
     */
    private function previousLanguages(array $state): array
    {
        $lists = (array)($state['generated_languages'] ?? []);
        if ($lists === []) {
            foreach ((array)($state['languages'] ?? []) as $language => $entry) {
                $lists[(string)$language] = (array)($entry['generated'] ?? []);
            }
        }
        return array_filter(array_map([$this->suggestions, 'clean'], $lists));
    }

    /**
     * The website's languages, the main one first: language => its store view (null = default).
     *
     * @param int $websiteId
     * @return array<string, int|null>
     */
    private function stores(int $websiteId): array
    {
        $primary = $this->languages->websiteLanguage($websiteId);
        $stores = [$primary !== '' ? $primary : 'en' => null];
        foreach ($this->languages->otherLanguages($websiteId) as $language => $storeId) {
            $stores += [(string)$language => (int)$storeId];
        }
        return array_slice($stores, 0, SuggestionsEnrich::MAX_LANGUAGES, true);
    }

    /**
     * Something is outstanding: never asked, a language not asked for yet, or an older generator.
     *
     * @param array $state
     * @param string[] $languages
     * @return bool
     */
    private function due(array $state, array $languages): bool
    {
        return empty($state['generated_at'])
            || ($state['generator'] ?? '') !== SuggestionsEnrich::GENERATOR
            || array_diff($languages, (array)($state['languages_done'] ?? [])) !== [];
    }

    /**
     * Try again later.
     *
     * @param int $websiteId
     * @param int $now
     * @param int $in seconds until the next attempt
     * @param string $error why
     * @param string $outcome outcome word
     * @return string
     */
    private function retry(int $websiteId, int $now, int $in, string $error, string $outcome): string
    {
        $this->update($websiteId, [
            'attempts' => (int)($this->state($websiteId)['attempts'] ?? 0) + 1,
            'next_at' => $now + $in,
            'locked_at' => 0,
            'last_error' => $error,
            'last_status' => $outcome,
        ]);
        return $outcome;
    }

    /**
     * Merge changes into the website's state.
     *
     * @param int $websiteId
     * @param array $changes
     * @return void
     */
    private function update(int $websiteId, array $changes): void
    {
        $this->flags->saveFlag(self::FLAG_PREFIX . $websiteId, array_merge($this->state($websiteId), $changes));
    }
}
