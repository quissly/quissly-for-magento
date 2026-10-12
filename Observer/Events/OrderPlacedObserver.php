<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Observer\Events;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Quissly\Search\Model\Events\PurchaseEvent;

/**
 * `sales_order_place_after`: an order paid offline counts as a purchase now (PurchaseEvent).
 */
class OrderPlacedObserver implements ObserverInterface
{
    /**
     * @param PurchaseEvent $purchase
     */
    public function __construct(private readonly PurchaseEvent $purchase)
    {
    }

    /**
     * Record the purchase when the order is paid offline.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (is_object($order)) {
            $this->purchase->placed($order);
        }
    }
}
