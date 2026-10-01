<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

use Magento\Framework\App\ResourceConnection;

/**
 * Answers the two questions the sync worker cannot ask a product collection,
 * because the collection filters on the very index that may be lagging.
 *
 * The worker maps queued products through a collection built with
 * addPriceData(), which INNER JOINs catalog_product_index_price. On a store
 * running the price indexer on schedule - the default - that index trails the
 * save that queued the row. A product therefore drops out of the collection for
 * two very different reasons: it was deleted, or it simply has not been indexed
 * yet. Treating the second as the first threw away brand-new products and
 * reported a clean run.
 *
 * These queries deliberately bypass the price index: they read the entity table
 * and Magento's own mview changelog, which is what records the work the indexer
 * still owes.
 */
class PriceIndexReadiness
{
    /**
     * Magento's mview id for the price index, and the changelog suffix it uses.
     */
    private const PRICE_VIEW_ID = 'catalog_product_price';
    private const STOCK_VIEW_ID = 'cataloginventory_stock';
    private const CHANGELOG_SUFFIX = '_cl';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Which of these ids still exist as products at all.
     *
     * An id absent here really is stale - the product is gone, and a real
     * deletion would have arrived as its own delete op.
     *
     * @param int[] $productIds
     * @return int[]
     */
    public function existing(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
            ->where('entity_id IN (?)', $productIds);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Which of these ids are assigned to the given website.
     *
     * A product that belongs to no website will never gain a price-index row
     * for it, so without this check it would defer for ever. It is not stale
     * and not broken - it is ineligible, which the worker already knows how to
     * handle.
     *
     * @param int[] $productIds
     * @param int $websiteId
     * @return int[]
     */
    public function assignedToWebsite(array $productIds, int $websiteId): array
    {
        if ($productIds === [] || $websiteId <= 0) {
            // Website 0 is the default scope rather than a real website; there
            // is no assignment to test, so treat every id as assigned.
            return $productIds;
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_website'), ['product_id'])
            ->where('product_id IN (?)', $productIds)
            ->where('website_id = ?', $websiteId);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Which of these products are out of stock, per Magento's stock index.
     *
     * The price index only carries in-stock products unless the store shows
     * out-of-stock ones, so a product that just went out of stock has no
     * index row to wait for. The worker loads these without the price join
     * instead of deferring them.
     *
     * @param int[] $productIds
     * @return int[]
     */
    public function outOfStock(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->distinct()
                ->from($this->resource->getTableName('cataloginventory_stock_status'), ['product_id'])
                ->where('product_id IN (?)', $productIds)
                ->where('stock_status = ?', 0);
            return array_map('intval', $connection->fetchCol($select));
        } catch (\Throwable $e) {
            // Never let a diagnostic query stop a sync.
            return [];
        }
    }

    /**
     * Which of these ids the price OR stock indexer still owes work for.
     *
     * Reads Magento's mview changelogs: rows written past the version the view
     * has processed are pending. Their index rows are therefore stale or
     * absent, and anything mapped from them would ship the PREVIOUS price or
     * stock status while reporting success.
     *
     * Returns nothing when the view is not on schedule (indexing on save keeps
     * the index current), and nothing if the changelog cannot be read - the
     * caller then behaves exactly as it did before this class existed.
     *
     * @param int[] $productIds
     * @return int[]
     */
    public function awaitingReindex(array $productIds): array
    {
        // Both indexes the record is built from. The stock status index lags a
        // save exactly like the price index does, and in_stock is read from
        // it: a product switched to Out of Stock with quantity left went out
        // as in_stock=true when the worker ran between the save and the cron
        // that applies the change (2026-09-11).
        return array_values(array_unique(array_merge(
            $this->pendingIn(self::PRICE_VIEW_ID, $productIds),
            $this->pendingIn(self::STOCK_VIEW_ID, $productIds)
        )));
    }

    /**
     * Products whose STOCK index change has not been applied yet.
     *
     * For records loaded without the price join: their prices never come
     * from the price index, but their in_stock still comes from the stock one.
     *
     * @param int[] $productIds
     * @return int[]
     */
    public function awaitingStockReindex(array $productIds): array
    {
        return $this->pendingIn(self::STOCK_VIEW_ID, $productIds);
    }

    /**
     * Whether the indexer cron is applying the stock or price changelog NOW.
     *
     * A rebuild of ours can interleave with that run: it reads the product
     * before our save, we write the new rows, it writes the old ones last.
     * The worker waits for idle before it rebuilds or reads anything.
     *
     * @return bool
     */
    public function indexerBusy(): bool
    {
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName('mview_state'), ['view_id'])
                ->where('view_id IN (?)', [self::PRICE_VIEW_ID, self::STOCK_VIEW_ID])
                ->where('status = ?', 'working')
                // A crashed indexer leaves "working" behind for ever; Magento's
                // own cron skips such a view until an operator resets it. Do not
                // let that hold the sync for ever as well.
                ->where('updated > ?', new \Zend_Db_Expr('DATE_SUB(NOW(), INTERVAL 10 MINUTE)'));
            return $connection->fetchCol($select) !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Ids with a changelog row newer than the view's applied version.
     *
     * @param string $viewId
     * @param int[] $productIds
     * @return int[]
     */
    private function pendingIn(string $viewId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        try {
            $connection = $this->resource->getConnection();
            $stateTable = $this->resource->getTableName('mview_state');
            $state = $connection->fetchRow(
                $connection->select()
                    ->from($stateTable, ['version_id', 'mode'])
                    ->where('view_id = ?', $viewId)
            );
            if (!is_array($state) || ($state['mode'] ?? '') !== 'enabled') {
                return [];
            }
            $changelog = $this->resource->getTableName($viewId . self::CHANGELOG_SUFFIX);
            if (!$connection->isTableExists($changelog)) {
                return [];
            }
            $select = $connection->select()
                ->distinct()
                ->from($changelog, ['entity_id'])
                ->where('entity_id IN (?)', $productIds)
                ->where('version_id > ?', (int)($state['version_id'] ?? 0));
            return array_map('intval', $connection->fetchCol($select));
        } catch (\Throwable $e) {
            // Never let a diagnostic query stop a sync.
            return [];
        }
    }
}
