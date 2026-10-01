<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

use Magento\Framework\App\ResourceConnection;

/**
 * The sync queue + ingest-state store. Semantics ported from the
 * proven Woo queue: PK dedup, DELETE op overrides a pending upsert, created_at
 * preserved so oldest-age flushing stays honest, attempts reset on re-enqueue.
 */
class QueueResource
{
    public const OP_UPSERT = 'upsert';
    public const OP_DELETE = 'delete';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Enqueue (or merge into) a queue row. Delete-wins; created_at preserved.
     *
     * @param int $productId
     * @param int $websiteId
     * @param string $op
     * @return void
     */
    public function enqueue(int $productId, int $websiteId, string $op): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('quissly_sync_queue');
        // Conditional upsert (delete-wins) is inexpressible via insertOnDuplicate.
        //
        // NOTE on `attempts = 0`: a re-enqueue restarts the attempt count, on
        // the reasoning that a fresh change deserves fresh attempts. The
        // consequence is that MAX_ATTEMPTS is NOT the ceiling it reads as -
        // ReconciliationCron re-enqueues every eligible product weekly, so a
        // permanently-failing product has its counter cleared before it can be
        // retired and retries forever on a weekly cycle. That is the designed
        // backstop working, not a leak, but it matters to anyone reasoning
        // about the ok/failed accounting the first-sync gate now depends on:
        // such a product contributes to `failed` indefinitely.
        $connection->query(
        // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            // deferred_until is cleared too: a genuine change deserves a fresh
            // chance immediately, rather than serving out a hold imposed when
            // the product's price index happened to be behind.
            "INSERT INTO {$table} (product_id, website_id, op, attempts, created_at)
             VALUES (:pid, :wid, :op, 0, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                op = IF(VALUES(op) = 'delete', 'delete', op),
                attempts = 0,
                deferred_until = NULL,
                version = version + 1",
            ['pid' => $productId, 'wid' => $websiteId, 'op' => $op]
        );
    }

    /**
     * Oldest pending rows for a website (read-only claim; removal on success).
     *
     * @param int $websiteId
     * @param int $limit
     * @param int[] $excludeProductIds Rows already touched this run
     * @return array<int, array{product_id: int, op: string, attempts: int}>
     */
    public function claimBatch(int $websiteId, int $limit, array $excludeProductIds = []): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('quissly_sync_queue'), ['product_id', 'op', 'attempts', 'version'])
            ->where('website_id = ?', $websiteId)
            // Rows held back for the price index step aside until their time is
            // up. Without this they sit at the head of the queue for ever -
        // claimBatch is oldest-first and a run only takes a couple of
            // batches, so a block of undeliverable rows is re-claimed every run
            // and the deliverable rows behind them are never reached.
            ->where('deferred_until IS NULL OR deferred_until <= NOW()')
            ->order(['created_at ASC', 'product_id ASC'])
            ->limit($limit);
        if ($excludeProductIds !== []) {
            $select->where('product_id NOT IN (?)', array_map('intval', $excludeProductIds));
        }
        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'product_id' => (int)$row['product_id'],
                'op' => (string)$row['op'],
                'attempts' => (int)$row['attempts'],
                'version' => (int)($row['version'] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * Pending row count for a website.
     *
     * @param int $websiteId
     * @return int
     */
    public function countPending(int $websiteId): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('quissly_sync_queue'), ['c' => new \Zend_Db_Expr('COUNT(*)')])
            ->where('website_id = ?', $websiteId);
        return (int)$connection->fetchOne($select);
    }

    /**
     * Pending rows that are products in their own right.
     *
     * Row counts and product counts diverge here. countPending() counts
     * ROWS, and on a catalogue of configurables most
     * rows are variants: they are claimed and DROPPED rather than sent,
     * because they ride inside their parent's variants[]. Reporting that
     * number beside a total that only ever counted sendable products produced
     * the dashboard line "Progress: 0 of 34 sent. 534 still queued." - two
     * different populations presented as one story, which reads as a broken
     * sync on a store that is working perfectly.
     *
     * This counts what the total was measured on: rows that are not a variant
     * of some parent. An ineligible row still counts until it is claimed and
     * dropped, which is honest - it IS still queued.
     *
     * @param int $websiteId
     * @return int
     */
    public function countPendingProducts(int $websiteId): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['q' => $this->resource->getTableName('quissly_sync_queue')],
                ['c' => new \Zend_Db_Expr('COUNT(*)')]
            )
            ->joinLeft(
                ['l' => $this->resource->getTableName('catalog_product_super_link')],
                'l.product_id = q.product_id',
                []
            )
            ->where('q.website_id = ?', $websiteId)
            ->where('l.product_id IS NULL');
        return (int)$connection->fetchOne($select);
    }

    /**
     * Settle rows: delete them, but only at the version they were claimed at.
     *
     * A save that lands while its product is in flight re-enqueues it - which
     * on this table is an UPDATE of the same row, bumping `version`. Deleting
     * by id alone then threw that newer change away with the settled one, and
     * the product stayed stale on Quissly until the weekly reconciliation. A
     * bumped row survives the settle and goes out again on the next run.
     *
     * @param int[] $productIds
     * @param int $websiteId
     * @param array $versions claimed version per product id; ids
     *        without one are deleted unconditionally (older in-flight state)
     * @return void
     */
    public function remove(array $productIds, int $websiteId, array $versions = []): void
    {
        if ($productIds === []) {
            return;
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('quissly_sync_queue');
        $unconditional = [];
        foreach ($productIds as $productId) {
            $productId = (int)$productId;
            if (!array_key_exists($productId, $versions)) {
                $unconditional[] = $productId;
                continue;
            }
            $connection->delete($table, [
                'product_id = ?' => $productId,
                'website_id = ?' => $websiteId,
                'version = ?' => (int)$versions[$productId],
            ]);
        }
        if ($unconditional !== []) {
            $connection->delete(
                $table,
                ['product_id IN (?)' => $unconditional, 'website_id = ?' => $websiteId]
            );
        }
    }

    /**
     * Bump attempts after a failed send; returns ids that exceeded maxAttempts
     * (already removed here - callers log them).
     *
     * @param int[] $productIds
     * @param int $websiteId
     * @param int $maxAttempts
     * @return int[] Dropped product ids
     */
    public function bumpAttempts(array $productIds, int $websiteId, int $maxAttempts): array
    {
        if ($productIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('quissly_sync_queue');
        $connection->update(
            $table,
            ['attempts' => new \Zend_Db_Expr('attempts + 1')],
            ['website_id = ?' => $websiteId, 'product_id IN (?)' => $productIds]
        );
        $select = $connection->select()
            ->from($table, ['product_id'])
            ->where('website_id = ?', $websiteId)
            ->where('attempts > ?', $maxAttempts)
            ->where('product_id IN (?)', $productIds);
        $dropped = array_map('intval', $connection->fetchCol($select));
        $this->remove($dropped, $websiteId);
        return $dropped;
    }

    /**
     * Hold rows back for $seconds before they may be claimed again.
     *
     * Used for rows waiting on the price index. They must stay queued - the
     * whole point of the earlier fix is that dropping them loses products - but
     * they must not be re-claimed on every single run either. claimBatch is
     * oldest-first and a run only takes a couple of batches, so a block of
     * undeliverable rows at the head of the queue starves every deliverable row
     * behind it. Stepping aside for a while lets the queue drain past them
     * while they keep their place and their attempts.
     *
     * @param int[] $productIds
     * @param int $websiteId
     * @param int $seconds
     * @return void
     */
    public function deferUntil(array $productIds, int $websiteId, int $seconds): void
    {
        if ($productIds === []) {
            return;
        }
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName('quissly_sync_queue'),
            ['deferred_until' => new \Zend_Db_Expr(sprintf('NOW() + INTERVAL %d SECOND', $seconds))],
            ['website_id = ?' => $websiteId, 'product_id IN (?)' => array_map('intval', $productIds)]
        );
    }

    /**
     * Drop the given rows only if they have been queued longer than $seconds.
     *
     * Used for rows held back waiting on the price indexer. They must not burn
     * a send attempt - waiting is not a failed delivery - but they cannot wait
     * for ever either, so age is the bound.
     *
     * @param int[] $productIds
     * @param int $websiteId
     * @param int $seconds
     * @return int[] the ids actually dropped
     */
    public function removeOlderThan(array $productIds, int $websiteId, int $seconds): array
    {
        if ($productIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('quissly_sync_queue');
        $select = $connection->select()
            ->from($table, ['product_id'])
            ->where('website_id = ?', $websiteId)
            ->where('product_id IN (?)', $productIds)
            ->where('created_at < ?', new \Zend_Db_Expr(sprintf('NOW() - INTERVAL %d SECOND', $seconds)));
        $expired = array_map('intval', $connection->fetchCol($select));
        $this->remove($expired, $websiteId);

        return $expired;
    }

    /**
     * Whether a product was ever successfully ingested for a website
     * (drives add-vs-update routing).
     *
     * @param int[] $productIds
     * @param int $websiteId
     * @return array<int, bool> product id => ingested
     */
    public function ingestedMap(array $productIds, int $websiteId): array
    {
        if ($productIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('quissly_product_state'), ['product_id'])
            ->where('website_id = ?', $websiteId)
            ->where('product_id IN (?)', $productIds);
        $ingested = array_flip(array_map('intval', $connection->fetchCol($select)));
        $map = [];
        foreach ($productIds as $id) {
            $map[(int)$id] = isset($ingested[(int)$id]);
        }
        return $map;
    }

    /**
     * Mark products ingested (idempotent).
     *
     * @param int[] $productIds
     * @param int $websiteId
     * @return void
     */
    public function markIngested(array $productIds, int $websiteId): void
    {
        if ($productIds === []) {
            return;
        }
        $connection = $this->resource->getConnection();
        $data = [];
        foreach ($productIds as $id) {
            $data[] = ['product_id' => (int)$id, 'website_id' => $websiteId];
        }
        $connection->insertOnDuplicate($this->resource->getTableName('quissly_product_state'), $data, ['product_id']);
    }

    /**
     * Clear the ingested marker (after delete → future re-add routes to POST).
     *
     * @param int[] $productIds
     * @param int $websiteId
     * @return void
     */
    public function clearIngested(array $productIds, int $websiteId): void
    {
        if ($productIds === []) {
            return;
        }
        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName('quissly_product_state'),
            ['product_id IN (?)' => $productIds, 'website_id = ?' => $websiteId]
        );
    }

    /**
     * All product ids currently marked ingested for a website
     * (reconciliation: detect no-longer-eligible products to delete).
     *
     * @param int $websiteId
     * @return int[]
     */
    public function allIngestedIds(int $websiteId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('quissly_product_state'), ['product_id'])
            ->where('website_id = ?', $websiteId);
        return array_map('intval', $connection->fetchCol($select));
    }
}
