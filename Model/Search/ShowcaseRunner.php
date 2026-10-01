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
 */
class ShowcaseRunner
{
    public const FLAG_PREFIX = 'quissly_showcase_w';
    public const MIN_ACCEPTABLE = 3;
    public const MAX_ATTEMPTS = 6;
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
        private readonly LoggerInterface $logger
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
     * @return bool
     */
    public function finished(int $websiteId): bool
    {
        $state = $this->state($websiteId);
        return !empty($state['generated_at']) || !empty($state['merchant_saved']);
    }

    /**
     * The generated list, [] when none.
     *
     * @param int $websiteId
     * @return string[]
     */
    public function generated(int $websiteId): array
    {
        return $this->suggestions->clean($this->state($websiteId)['generated'] ?? []);
    }

    /**
     * A merchant wrote their own list: generation never writes after this.
     *
     * @param int $websiteId
     * @return void
     */
    public function merchantSaved(int $websiteId): void
    {
        $this->update($websiteId, ['merchant_saved' => true]);
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
            return 'finished';
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
