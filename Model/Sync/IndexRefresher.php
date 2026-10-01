<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

use Magento\Framework\Indexer\IndexerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds the stock and price index rows of specific products, on demand.
 *
 * On a store indexing on schedule, a save writes the change to the product
 * but the indexes only follow when the indexer cron applies the changelog -
 * and that cron runs in the same minute as the sync worker, in another
 * process, with no ordering between them. The worker reads in_stock from
 * the stock index and loads the batch through the price index, so a run
 * that wins the race sends the state from BEFORE the save.
 *
 * Waiting for the cron makes the record late; this makes it current. Each
 * indexer's reindexList() rebuilds the rows for just these ids, synchronously,
 * whatever the schedule says. The cron re-applies the same changelog entries
 * later, which is harmless: indexing is idempotent.
 *
 * Stock first, then price: the price index drops out-of-stock products, so
 * it must read the stock rows this call has just written.
 */
class IndexRefresher
{
    /** Indexers rebuilt, in dependency order. */
    private const INDEXERS = ['cataloginventory_stock', 'catalog_product_price'];

    /**
     * @param IndexerRegistry $indexers
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly IndexerRegistry $indexers,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Rebuild the index rows of these products.
     *
     * False when any rebuild failed, in which case the caller falls back to
     * waiting for the cron.
     *
     * @param int[] $productIds
     * @return bool
     */
    public function refresh(array $productIds): bool
    {
        if ($productIds === []) {
            return true;
        }
        foreach (self::INDEXERS as $indexerId) {
            try {
                $this->indexers->get($indexerId)->reindexList($productIds);
            } catch (\Throwable $e) {
                // ids/counts only - never product data.
                $this->logger->warning(sprintf(
                    '[quissly] index refresh failed indexer=%s products=%d: %s',
                    $indexerId,
                    count($productIds),
                    $e->getMessage()
                ));
                return false;
            }
        }
        return true;
    }
}
