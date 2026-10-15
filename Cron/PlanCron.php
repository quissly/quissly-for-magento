<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Api\StoreBilling;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Connect\PlanGate;

/**
 * What each connected website's plan includes (Model\Connect\PlanGate), asked
 * again once Quissly's last answer is 10 minutes old - so a plan changed outside
 * the admin (Quissly's console, a payment) reaches the storefront by itself.
 */
class PlanCron
{
    /**
     * @param PlanGate $planGate
     * @param StoreBilling $billing
     * @param StoreManagerInterface $storeManager
     * @param Settings $settings
     */
    public function __construct(
        private readonly PlanGate $planGate,
        private readonly StoreBilling $billing,
        private readonly StoreManagerInterface $storeManager,
        private readonly Settings $settings
    ) {
    }

    /**
     * Ask about every connected website whose answer is due.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            if ($this->settings->apiToken($websiteId) === null) {
                continue;
            }
            $this->planGate->refresh($websiteId, function () use ($websiteId): ?array {
                return $this->billing->subscriptions($websiteId);
            }, false, time());
        }
    }
}
