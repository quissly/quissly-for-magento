<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Events\EventSender;
use Quissly\Search\Model\Events\VersionReport;

/**
 * Per-minute send of the queued events, every website - after the version report has queued
 * its own when one is due (Model/Events/VersionReport).
 */
class EventsCron
{
    /**
     * @param EventSender $sender
     * @param StoreManagerInterface $storeManager
     * @param VersionReport $versionReport
     */
    public function __construct(
        private readonly EventSender $sender,
        private readonly StoreManagerInterface $storeManager,
        private readonly VersionReport $versionReport
    ) {
    }

    /**
     * Send what is due.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->versionReport->run(time());
        } catch (\Throwable $e) {
            unset($e); // never in the way of the shoppers' events
        }
        foreach ($this->storeManager->getWebsites() as $website) {
            $this->sender->run((int)$website->getId(), time());
        }
    }
}
