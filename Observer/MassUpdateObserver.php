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
 * Admin mass attribute update (bypasses per-product saves - a known
 * change-detection gap) → enqueue each affected product.
 */
class MassUpdateObserver implements ObserverInterface
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
        foreach ((array)$observer->getEvent()->getProductIds() as $productId) {
            $this->enqueuer->enqueueUpsert((int)$productId);
        }
    }
}
