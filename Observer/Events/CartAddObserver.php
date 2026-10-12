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
 * `checkout_cart_product_add_after` (the storefront cart, the chat's Add to Cart included):
 * an add_to_cart event - the product added, the variant chosen, the quantity.
 */
class CartAddObserver implements ObserverInterface
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
     * Record the add to cart.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        $item = $observer->getEvent()->getData('quote_item');
        if (!is_object($product) || (int)$product->getId() <= 0) {
            return;
        }
        $variantId = null;
        // A configurable product's line holds the variant chosen as its simple_product option.
        $option = is_object($item) ? $item->getOptionByCode('simple_product') : null;
        if (is_object($option) && is_object($option->getProduct())) {
            $variantId = (int)$option->getProduct()->getId();
        }
        $this->recorder->shopper(
            EventBody::ADD_TO_CART,
            $this->body->addToCart((int)$product->getId(), $variantId, (float)($product->getCartQty() ?: 1))
        );
    }
}
