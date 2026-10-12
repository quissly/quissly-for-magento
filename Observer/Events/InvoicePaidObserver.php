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
 * `sales_order_invoice_pay`: an order paid online counts as a purchase at its first paid
 * invoice (PurchaseEvent).
 */
class InvoicePaidObserver implements ObserverInterface
{
    /**
     * @param PurchaseEvent $purchase
     */
    public function __construct(private readonly PurchaseEvent $purchase)
    {
    }

    /**
     * Record the purchase at the order's first payment.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $invoice = $observer->getEvent()->getData('invoice');
        if (is_object($invoice)) {
            $this->purchase->invoicePaid($invoice);
        }
    }
}
