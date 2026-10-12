<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Events;

use Magento\Framework\App\ResourceConnection;

/**
 * Events waiting to be sent (`quissly_event_queue`).
 *
 * A shopper's request only writes a row; `EventSender` (the `quissly_events` cron) sends
 * them, so nobody waits for Quissly. Times are unix seconds.
 */
class EventQueue
{
    private const TABLE = 'quissly_event_queue';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Queue one event body.
     *
     * @param int $websiteId
     * @param array $body EventBody::envelope()
     * @param int $now
     * @return void
     */
    public function add(int $websiteId, array $body, int $now): void
    {
        $this->resource->getConnection()->insert($this->table(), [
            'website_id' => $websiteId,
            'body' => (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'attempts' => 0,
            'created_at' => $now,
            'next_at' => 0,
        ]);
    }

    /**
     * The oldest events due now.
     *
     * @param int $websiteId
     * @param int $now
     * @param int $limit
     * @return array<int, array{queue_id: int, body: array, attempts: int, created_at: int}>
     */
    public function due(int $websiteId, int $now, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->table(), ['queue_id', 'body', 'attempts', 'created_at'])
            ->where('website_id = ?', $websiteId)
            ->where('next_at <= ?', $now)
            ->order('queue_id ASC')
            ->limit($limit);
        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $body = json_decode((string)$row['body'], true);
            $rows[] = [
                'queue_id' => (int)$row['queue_id'],
                'body' => is_array($body) ? $body : [],
                'attempts' => (int)$row['attempts'],
                'created_at' => (int)$row['created_at'],
            ];
        }
        return $rows;
    }

    /**
     * Remove sent (or dropped) events.
     *
     * @param int[] $ids
     * @return void
     */
    public function remove(array $ids): void
    {
        if ($ids !== []) {
            $this->resource->getConnection()->delete($this->table(), ['queue_id IN (?)' => $ids]);
        }
    }

    /**
     * Try an event again later.
     *
     * @param int $id
     * @param int $attempts tries made so far
     * @param int $nextAt
     * @return void
     */
    public function retry(int $id, int $attempts, int $nextAt): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['attempts' => $attempts, 'next_at' => $nextAt],
            ['queue_id = ?' => $id]
        );
    }

    /**
     * Drop a website's events created before a time, or all of them.
     *
     * @param int $websiteId
     * @param int|null $before null = every event
     * @return int rows removed
     */
    public function clear(int $websiteId, ?int $before = null): int
    {
        $where = ['website_id = ?' => $websiteId];
        if ($before !== null) {
            $where['created_at < ?'] = $before;
        }
        return (int)$this->resource->getConnection()->delete($this->table(), $where);
    }

    /**
     * Keep at most $keep events for a website, dropping the oldest.
     *
     * Bounds the table while Quissly cannot be reached on a busy store.
     *
     * @param int $websiteId
     * @param int $keep
     * @return int rows removed
     */
    public function trim(int $websiteId, int $keep): int
    {
        $connection = $this->resource->getConnection();
        $cutoff = $connection->fetchOne(
            $connection->select()
                ->from($this->table(), ['queue_id'])
                ->where('website_id = ?', $websiteId)
                ->order('queue_id DESC')
                ->limit(1, $keep)
        );
        if ($cutoff === false || $cutoff === null) {
            return 0;
        }
        return (int)$connection->delete(
            $this->table(),
            ['website_id = ?' => $websiteId, 'queue_id <= ?' => (int)$cutoff]
        );
    }

    /**
     * The table's real name.
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
