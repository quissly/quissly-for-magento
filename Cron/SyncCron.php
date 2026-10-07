<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Connect\Onboarding;
use Quissly\Search\Model\Sync\SyncWorker;

/**
 * Per-minute queue drain, every website.
 */
class SyncCron
{
    /**
     * @param SyncWorker $worker
     * @param StoreManagerInterface $storeManager
     * @param Onboarding $onboarding
     */
    public function __construct(
        private readonly SyncWorker $worker,
        private readonly StoreManagerInterface $storeManager,
        private readonly Onboarding $onboarding
    ) {
    }

    /**
     * Drain up to two batches per website per run.
     *
     * @return void
     */
    public function execute(): void
    {
        // Quissly Setup's first sync starts here when the Setup page is shut.
        $this->onboarding->advance();
        foreach ($this->storeManager->getWebsites() as $website) {
            $this->worker->run((int)$website->getId(), 2);
        }
    }
}
