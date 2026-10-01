<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Sync\SyncWorker;

/**
 * Per-minute queue drain, every website.
 */
class SyncCron
{
    /**
     * @param SyncWorker $worker
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly SyncWorker $worker,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Drain up to two batches per website per run.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            $this->worker->run((int)$website->getId(), 2);
        }
    }
}
