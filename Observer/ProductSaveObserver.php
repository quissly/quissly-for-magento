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
 * Product create/update → enqueue upsert (enqueue only).
 */
class ProductSaveObserver implements ObserverInterface
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
            $this->enqueuer->enqueueUpsert(
                (int)$product->getId(),
                array_map('intval', (array)$product->getWebsiteIds())
            );
        }
    }
}
