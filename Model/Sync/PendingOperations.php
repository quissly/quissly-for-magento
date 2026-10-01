<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

use Magento\Framework\FlagManager;

/**
 * Catalog operations Quissly has accepted but not yet finished.
 *
 * A batch is sent, Quissly answers with an operation id, and the worker polls
 * it for a bounded time. When that time runs out the batch used to be counted
 * failed and re-sent, so a slow ingest (a fresh project embedding ten images
 * per product) was sent again and again, each copy queued behind the last on
 * Quissly's side, until the rows burned their attempts and were dropped while
 * the very first copy was still being processed (VM, 2026-09-10).
 *
 * Now the operation is remembered here, its rows stay queued but untouchable,
 * and every later run checks on it once before sending anything new. Only a
 * terminal verdict settles the rows; only MAX_AGE without one gives up on it.
 */
class PendingOperations
{
    private const FLAG_PREFIX = 'quissly_sync_pending_ops_w';

    /**
     * @param FlagManager $flagManager
     */
    public function __construct(private readonly FlagManager $flagManager)
    {
    }

    /**
     * Every remembered operation for a website, oldest first.
     *
     * @param int $websiteId
     * @return array list of {operation_id, method, product_ids, variant_map, sent_at}
     */
    public function all(int $websiteId): array
    {
        $stored = $this->flagManager->getFlagData(self::FLAG_PREFIX . $websiteId);
        return is_array($stored) ? array_values($stored) : [];
    }

    /**
     * Remember an operation that is still running on Quissly's side.
     *
     * @param int $websiteId
     * @param string $operationId
     * @param string $method POST|PUT
     * @param int[] $productIds queued product ids the operation carries
     * @param array $variantMap wire id => queued id
     * @param array $versions queue version each row was claimed at
     * @param array $stock in_stock flag sent, by product and variant id
     * @return void
     */
    public function add(
        int $websiteId,
        string $operationId,
        string $method,
        array $productIds,
        array $variantMap,
        array $versions = [],
        array $stock = []
    ): void {
        $ops = $this->all($websiteId);
        $ops[] = [
            'operation_id' => $operationId,
            'method' => $method,
            'product_ids' => array_values(array_map('intval', $productIds)),
            'variant_map' => $variantMap,
            // The queue versions these rows were claimed at, so the settle on a
            // later run removes exactly what was sent and nothing newer.
            'versions' => array_map('intval', $versions),
            // in_stock as sent, per product and variant id - the snapshot is
            // written from this when the operation finally settles.
            'stock' => array_map('intval', $stock),
            'sent_at' => time(),
        ];
        $this->flagManager->saveFlag(self::FLAG_PREFIX . $websiteId, $ops);
    }

    /**
     * Forget an operation (settled, or given up on).
     *
     * @param int $websiteId
     * @param string $operationId
     * @return void
     */
    public function remove(int $websiteId, string $operationId): void
    {
        $kept = array_values(array_filter(
            $this->all($websiteId),
            static fn (array $op): bool => $op['operation_id'] !== $operationId
        ));
        if ($kept === []) {
            $this->flagManager->deleteFlag(self::FLAG_PREFIX . $websiteId);
            return;
        }
        $this->flagManager->saveFlag(self::FLAG_PREFIX . $websiteId, $kept);
    }

    /**
     * Product ids that belong to a running operation - not to be re-sent.
     *
     * @param int $websiteId
     * @return int[]
     */
    public function productIds(int $websiteId): array
    {
        $ids = [];
        foreach ($this->all($websiteId) as $op) {
            foreach ($op['product_ids'] as $id) {
                $ids[(int)$id] = (int)$id;
            }
        }
        return array_values($ids);
    }
}
