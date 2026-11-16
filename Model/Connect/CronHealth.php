<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Connect;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Whether Magento's cron runs the catalog sync: the last successful quissly_sync_worker in
 * Magento's own cron_schedule. Quissly Setup's Go live says so while it has not run lately -
 * without the cron nothing is sent at all (the page only starts the first sync).
 */
class CronHealth
{
    /** The sync job runs every minute; this long without it means the cron is not running. */
    public const FRESH_SECONDS = 600;

    private const JOB = 'quissly_sync_worker';

    /**
     * @param CollectionFactory $schedules
     * @param DirectoryList $directoryList
     */
    public function __construct(
        private readonly CollectionFactory $schedules,
        private readonly DirectoryList $directoryList
    ) {
    }

    /**
     * The Go live answer's cron part: whether the cron ran lately, and the line that runs it.
     *
     * @param int $now
     * @return array{fresh: bool, line: string}
     */
    public function status(int $now): array
    {
        return [
            'fresh' => $this->isFresh($this->lastRun(), $now),
            'line' => '* * * * * php ' . rtrim($this->directoryList->getRoot(), '/') . '/bin/magento cron:run',
        ];
    }

    /**
     * Whether a run at $lastRun is recent enough at $now.
     *
     * @param int|null $lastRun
     * @param int $now
     * @return bool
     */
    public function isFresh(?int $lastRun, int $now): bool
    {
        return $lastRun !== null && $now - $lastRun <= self::FRESH_SECONDS;
    }

    /**
     * When the sync job last finished, or null when it never did.
     *
     * @return int|null
     */
    private function lastRun(): ?int
    {
        try {
            $collection = $this->schedules->create()
                ->addFieldToFilter('job_code', self::JOB)
                ->addFieldToFilter('status', 'success')
                ->setOrder('finished_at', 'DESC')
                ->setPageSize(1);
            $finished = (string)$collection->getFirstItem()->getData('finished_at');
        } catch (\Throwable $e) {
            return null;
        }
        $time = $finished !== '' ? strtotime($finished . ' UTC') : false;

        return $time === false ? null : $time;
    }
}
