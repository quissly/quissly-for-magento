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
use Quissly\Search\Model\Api\LiveSearchClient;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Sync\SyncCompletion;

/**
 * Generates a website's search bar suggestions from its own catalog - once, in the
 * background (Cron/ShowcaseCron), after the first catalog sync (port of the Quissly Shopify
 * app's; the WooCommerce and CS-Cart plugins have the same):
 *
 *  1. read catalog facts (ShowcaseCatalog: the 100 most recently changed searchable products);
 *  2. build candidates (ShowcaseQueries) and RUN each through search - kept only with at least
 *     2 results (up to 12 searches);
 *  3. fewer than 3 kept = the index may still be filling: retry in an hour (6 attempts; the
 *     last keeps whatever it found, or - nothing at all - gives up, where the Shopify runner
 *     retries for ever);
 *  4. write them as the list (SearchSuggestions) ONLY while Quissly holds no list - a
 *     merchant's own list is never overwritten, even one written elsewhere.
 *
 * A merchant who saves their own list (an actual change) ends it for good; saving the page
 * unchanged does not (the Shopify app stops on any save). The generated list is kept in the
 * flag for Configuration's "use the generated suggestions".
 *
 * Then, once the main list is done, a list for each other language the website's store views
 * use (SuggestionLanguage->otherLanguages(), at most MAX_LANGUAGES, those ShowcaseQueries can phrase), from
 * that store view's catalog - the Shopify app's per-language lists. Each language is tried once
 * and written only while Quissly holds no list for it; a merchant's save of a language's list
 * ends it for that language. A store view added later is picked up on a later tick.
 */
class ShowcaseRunner
{
    public const FLAG_PREFIX = 'quissly_showcase_w';
    public const MIN_ACCEPTABLE = 3;
    public const MAX_ATTEMPTS = 6;
    public const MAX_LANGUAGES = 3;
    private const LOCK_SECONDS = 600;
    private const HOUR = 3600;
    private const DAY = 86400;

    /**
     * @param ShowcaseQueries $queries
     * @param ShowcaseCatalog $catalog
     * @param LiveSearchClient $searchClient
     * @param SearchSuggestions $suggestions
     * @param SyncCompletion $completion
     * @param Settings $settings
     * @param FlagManager $flags
     * @param TypeListInterface $cacheTypeList
     * @param LoggerInterface $logger
     * @param SuggestionLanguage $languages
     */
    public function __construct(
        private readonly ShowcaseQueries $queries,
        private readonly ShowcaseCatalog $catalog,
        private readonly LiveSearchClient $searchClient,
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
     * State: generated, generated_at, attempts, next_at, locked_at, last_error, merchant_saved.
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
     * Nothing left to do: generated (or given up), or the merchant wrote their own list.
     *
     * @param int $websiteId
     * @param string|null $language another language's list; null = the main list
     * @return bool
     */
    public function finished(int $websiteId, ?string $language = null): bool
    {
        $state = $language === null
            ? $this->state($websiteId)
            : (array)($this->state($websiteId)['languages'][$language] ?? []);
        return !empty($state['generated_at']) || !empty($state['merchant_saved']);
    }

    /**
     * The generated list, [] when none.
     *
     * @param int $websiteId
     * @param string|null $language another language's list; null = the main list
     * @return string[]
     */
    public function generated(int $websiteId, ?string $language = null): array
    {
        $state = $language === null
            ? $this->state($websiteId)
            : (array)($this->state($websiteId)['languages'][$language] ?? []);
        return $this->suggestions->clean($state['generated'] ?? []);
    }

    /**
     * A merchant wrote their own list: generation never writes after this.
     *
     * @param int $websiteId
     * @param string|null $language another language's list; null = the main list
     * @return void
     */
    public function merchantSaved(int $websiteId, ?string $language = null): void
    {
        if ($language === null) {
            $this->update($websiteId, ['merchant_saved' => true]);
            return;
        }
        $this->updateLanguage($websiteId, $language, ['merchant_saved' => true]);
    }

    /**
     * The cron tick for one website: generate when due. Never throws.
     *
     * @param int $websiteId
     * @param int|null $now
     * @return string what happened
     */
    public function tick(int $websiteId, ?int $now = null): string
    {
        $now = $now ?? time();
        if ($this->finished($websiteId)) {
            return $this->languagesTick($websiteId, $now);
        }
        if (!$this->settings->isConfigured($websiteId)
            || !$this->completion->isComplete($websiteId)
            || $this->suggestions->serviceId($websiteId) === null
        ) {
            return 'not_ready';
        }
        $state = $this->state($websiteId);
        if ((int)($state['next_at'] ?? 0) > $now) {
            return 'not_due';
        }
        if ((int)($state['locked_at'] ?? 0) > $now - self::LOCK_SECONDS) {
            return 'locked';
        }
        $this->update($websiteId, ['locked_at' => $now]);

        try {
            return $this->generate($websiteId, $now, (int)($state['attempts'] ?? 0) + 1);
        } catch (\Throwable $e) {
            return $this->retry($websiteId, $now, self::HOUR, substr($e->getMessage(), 0, 300), 'error');
        }
    }

    /**
     * One attempt, end to end.
     *
     * @param int $websiteId
     * @param int $now
     * @param int $attempts this attempt's number
     * @return string
     */
    private function generate(int $websiteId, int $now, int $attempts): string
    {
        $candidates = $this->queries->buildCandidates($this->catalog->facts($websiteId));
        if ($candidates === []) {
            $error = 'No searchable products to build suggestions from.';
            return $this->retry($websiteId, $now, self::DAY, $error, 'no_products');
        }
        $accepted = $this->queries->validate($candidates, function (string $query) use ($websiteId): int {
            $outcome = $this->searchClient->search($query, 1, 5, $websiteId);
            if (!$outcome->isOk()) {
                throw new \RuntimeException('search ' . $outcome->code);
            }
            return max($outcome->total, count($outcome->ids));
        });

        $lastAttempt = $attempts >= self::MAX_ATTEMPTS;
        if ($lastAttempt && $accepted === []) {
            $this->update($websiteId, [
                'generated' => [], 'generated_at' => $now, 'attempts' => $attempts, 'locked_at' => 0,
                'last_error' => 'No candidate search returned results after ' . $attempts . ' attempts.',
            ]);
            return 'gave_up';
        }
        if (count($accepted) < self::MIN_ACCEPTABLE && !($lastAttempt && $accepted !== [])) {
            // Most likely the search index is still filling after the first sync.
            return $this->retry(
                $websiteId,
                $now,
                self::HOUR,
                sprintf('Only %d of %d candidate searches returned results.', count($accepted), count($candidates)),
                'only_' . count($accepted) . '_validated'
            );
        }

        $queries = $this->queries->pick($accepted);
        $current = $this->suggestions->read($websiteId);
        if ($current === null) {
            return $this->retry($websiteId, $now, self::HOUR, 'Quissly could not be read.', 'unreadable');
        }
        $done = [
            'generated' => $queries, 'generated_at' => $now, 'attempts' => $attempts,
            'locked_at' => 0, 'last_error' => '',
        ];
        if ($current['queries'] !== [] || !empty($this->state($websiteId)['merchant_saved'])) {
            // Someone already wrote a list: keep theirs, remember ours for "use generated".
            $this->update($websiteId, $done);
            return 'kept_existing';
        }
        $error = $this->suggestions->save($websiteId, $current['enabled'], $queries);
        if ($error !== null) {
            return $this->retry($websiteId, $now, self::HOUR, $error, 'write_failed');
        }
        $this->update($websiteId, $done);
        // The overlay's list is part of every cached page (as Config/Backend/SearchSuggestions).
        $this->cacheTypeList->cleanType(PageCache::TYPE_IDENTIFIER);
        $this->cacheTypeList->cleanType(Block::TYPE_IDENTIFIER);
        $this->logger->info(
            sprintf('[quissly] search bar suggestions generated website=%d count=%d', $websiteId, count($queries))
        );
        return 'written';
    }

    /**
     * The other languages' lists, once the main one is done: each language still to do, once.
     *
     * @param int $websiteId
     * @param int $now
     * @return string what happened ('finished' when there is nothing to do)
     */
    private function languagesTick(int $websiteId, int $now): string
    {
        $todo = [];
        foreach ($this->languages->otherLanguages($websiteId) as $language => $storeId) {
            if (isset(ShowcaseQueries::PHRASES[$language]) && !$this->finished($websiteId, $language)) {
                $todo[$language] = $storeId;
            }
        }
        $todo = array_slice($todo, 0, self::MAX_LANGUAGES, true);
        if ($todo === []) {
            return 'finished';
        }
        $state = $this->state($websiteId);
        if ((int)($state['languages_next_at'] ?? 0) > $now) {
            return 'not_due';
        }
        if ((int)($state['locked_at'] ?? 0) > $now - self::LOCK_SECONDS
            || !$this->settings->isConfigured($websiteId)
            || $this->suggestions->serviceId($websiteId) === null
        ) {
            return 'not_ready';
        }
        $this->update($websiteId, ['locked_at' => $now]);
        try {
            $written = [];
            foreach ($todo as $language => $storeId) {
                $written[] = $language . ':' . $this->generateLanguage($websiteId, $language, $storeId, $now);
            }
            $this->update($websiteId, ['locked_at' => 0]);
            return 'languages ' . implode(' ', $written);
        } catch (\Throwable $e) {
            $this->update($websiteId, [
                'locked_at' => 0, 'languages_next_at' => $now + self::HOUR,
                'last_error' => substr($e->getMessage(), 0, 300),
            ]);
            return 'languages_error';
        }
    }

    /**
     * One language's list, from that store view's catalog.
     *
     * @param int $websiteId
     * @param string $language
     * @param int $storeId
     * @param int $now
     * @return string
     */
    private function generateLanguage(int $websiteId, string $language, int $storeId, int $now): string
    {
        $candidates = $this->queries->buildCandidates($this->catalog->facts($websiteId, $storeId), $language);
        $accepted = $candidates === [] ? [] : $this->queries->validate(
            $candidates,
            function (string $query) use ($websiteId): int {
                $outcome = $this->searchClient->search($query, 1, 5, $websiteId);
                return $outcome->isOk() ? max($outcome->total, count($outcome->ids)) : 0;
            }
        );
        $queries = $accepted === [] ? [] : $this->queries->pick($accepted);
        $current = $this->suggestions->read($websiteId);
        if ($current === null) {
            throw new \RuntimeException('Quissly could not be read.');
        }
        $outcome = 'kept_existing';
        if ($queries === []) {
            $outcome = 'none';
        } elseif (($current['by_language'][$language] ?? []) === []) {
            $error = $this->suggestions->saveLanguage($websiteId, $language, $queries);
            if ($error !== null) {
                throw new \RuntimeException($error);
            }
            $outcome = 'written';
            $this->cacheTypeList->cleanType(PageCache::TYPE_IDENTIFIER);
            $this->cacheTypeList->cleanType(Block::TYPE_IDENTIFIER);
            $this->logger->info(sprintf(
                '[quissly] search bar suggestions generated website=%d language=%s count=%d',
                $websiteId,
                $language,
                count($queries)
            ));
        }
        $this->updateLanguage($websiteId, $language, ['generated' => $queries, 'generated_at' => $now]);
        return $outcome;
    }

    /**
     * Merge changes into one language's state.
     *
     * @param int $websiteId
     * @param string $language
     * @param array $changes
     * @return void
     */
    private function updateLanguage(int $websiteId, string $language, array $changes): void
    {
        $languages = (array)($this->state($websiteId)['languages'] ?? []);
        $languages[$language] = array_merge((array)($languages[$language] ?? []), $changes);
        $this->update($websiteId, ['languages' => $languages]);
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
