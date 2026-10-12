<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Events\EventSender;

/**
 * Per-minute send of the queued shopping events, every website.
 */
class EventsCron
{
    /**
     * @param EventSender $sender
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly EventSender $sender,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Send what is due.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            $this->sender->run((int)$website->getId(), time());
        }
    }
}
