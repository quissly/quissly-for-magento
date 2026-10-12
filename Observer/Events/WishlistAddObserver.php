<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Observer\Events;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Quissly\Search\Model\Events\EventBody;
use Quissly\Search\Model\Events\Recorder;

/**
 * `wishlist_add_product`: an add_to_wishlist event.
 */
class WishlistAddObserver implements ObserverInterface
{
    /**
     * @param Recorder $recorder
     * @param EventBody $body
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly EventBody $body
    ) {
    }

    /**
     * Record the add to wishlist.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if (is_object($product) && (int)$product->getId() > 0) {
            $this->recorder->shopper(EventBody::WISHLIST, $this->body->wishlist((int)$product->getId()));
        }
    }
}
