<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

use Magento\Framework\App\ResourceConnection;

/**
 * The in_stock flag each product and variant was last SENT with.
 *
 * Event observers catch admin saves, imports and mass updates, but Magento
 * takes stock on a SALE without saving the stock item - legacy
 * Stock::correctItemsQty writes SQL directly, and Multi-Source Inventory
 * places a reservation - so a product selling out was the one stock change
 * the plugin could not see. Proven with a real order on 2026-09-11: Magento
 * flipped its stock status at once, the plugin's queue stayed empty.
 *
 * This does not watch events. It records, at settle time, the flag every
 * sent parent and variant carried in the record, and StockReconcileCron
 * asks Magento's stock registry - the SAME source the mapper reads, which
 * under MSI is reservation-aware salability for the website's stock - the
 * same question every minute, in chunks, and re-queues the products whose
 * answer changed. Same source on both sides, so a difference means the
 * product's stock changed and nothing else: no churn, no blind spot.
 */
class StockSnapshot
{
    private const TABLE = 'quissly_stock_snapshot';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Record the flag these products were sent with.
     *
     * @param array $inStockById in_stock per product id, parents AND variants
     * @param int $websiteId
     * @return void
     */
    public function record(array $inStockById, int $websiteId): void
    {
        $rows = [];
        foreach ($inStockById as $productId => $inStock) {
            if ((int)$productId <= 0) {
                continue;
            }
            $rows[] = [
                'product_id' => (int)$productId,
                'website_id' => $websiteId,
                'stock_status' => $inStock ? 1 : 0,
            ];
        }
        if ($rows === []) {
            return;
        }
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            $rows,
            ['stock_status']
        );
    }

    /**
     * A chunk of the snapshot, by ascending product id, for the cron to check.
     *
     * @param int $websiteId
     * @param int $afterProductId cursor: rows with a greater id
     * @param int $limit
     * @return array<int, int> product id => flag as sent (1|0)
     */
    public function chunk(int $websiteId, int $afterProductId, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['product_id', 'stock_status'])
            ->where('website_id = ?', $websiteId)
            ->where('product_id > ?', $afterProductId)
            ->order('product_id ASC')
            ->limit($limit);
        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int)$row['product_id']] = (int)$row['stock_status'];
        }
        return $out;
    }

    /**
     * Drop the snapshot of products that left the catalogue.
     *
     * @param int[] $productIds
     * @param int $websiteId
     * @return void
     */
    public function forget(array $productIds, int $websiteId): void
    {
        if ($productIds === []) {
            return;
        }
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['product_id IN (?)' => array_map('intval', $productIds), 'website_id = ?' => $websiteId]
        );
    }
}
