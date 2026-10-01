<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Quissly\Search\Model\Sync\Enqueuer;

/**
 * Stock item saves: enqueue only on STATUS flips (in/out of stock), never on
 * quantity ticks - the Woo rule that keeps order-placing shoppers from
 * flooding the queue.
 */
class StockObserver implements ObserverInterface
{
    /**
     * @param Enqueuer $enqueuer
     */
    public function __construct(private readonly Enqueuer $enqueuer)
    {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getItem();
        if ($item === null || !$item->getProductId()) {
            return;
        }
        $wasInStock = (bool)$item->getOrigData('is_in_stock');
        $isInStock = (bool)$item->getIsInStock();
        if ($item->getOrigData() !== null && $wasInStock === $isInStock) {
            return; // quantity-only change
        }
        $this->enqueuer->enqueueUpsert((int)$item->getProductId());
    }
}
