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
 * Product deletion → enqueue delete on every website it belonged to.
 */
class ProductDeleteObserver implements ObserverInterface
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
        $product = $observer->getEvent()->getProduct();
        if ($product && $product->getId()) {
            $this->enqueuer->enqueueDelete((int)$product->getId());
        }
    }
}
