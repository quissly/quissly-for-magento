<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Events;

/**
 * When an order counts as a purchase, and what it says (Keso, 2026-10-08: when it is paid).
 *
 * Online payment: when its first invoice is paid (`sales_order_invoice_pay` - at checkout
 * for "authorize and capture", at capture for "authorize only"); a second, partial invoice
 * does not count the order again. Offline payment (check / money order, bank transfer,
 * cash on delivery, a free order): when the order is placed (`sales_order_place_after`),
 * since nothing tells Magento when that money arrives - and its later invoice is skipped.
 * Either way an order is counted once.
 *
 * The order and invoice are Magento's sales models, passed untyped: the unit tier has no
 * Magento_Sales (tests/unit/bootstrap.php).
 */
class PurchaseEvent
{
    /** Rounding slack when comparing what was paid with the invoice. */
    private const CENT = 0.005;

    /**
     * @param EventBody $body
     * @param Recorder $recorder
     */
    public function __construct(
        private readonly EventBody $body,
        private readonly Recorder $recorder
    ) {
    }

    /**
     * An order was placed.
     *
     * @param object $order \Magento\Sales\Model\Order
     * @return bool recorded
     */
    public function placed(object $order): bool
    {
        return $this->offline($order) && $this->record($order);
    }

    /**
     * An invoice was paid.
     *
     * @param object $invoice \Magento\Sales\Model\Order\Invoice
     * @return bool recorded
     */
    public function invoicePaid(object $invoice): bool
    {
        $order = $invoice->getOrder();
        if (!is_object($order) || $this->offline($order)) {
            return false;
        }
        // Invoice::pay() has already added this invoice to the order's total paid.
        $firstPayment = (float)$order->getTotalPaid() <= (float)$invoice->getGrandTotal() + self::CENT;
        return $firstPayment && $this->record($order);
    }

    /**
     * The event's metadata: the order number, total, currency and lines.
     *
     * A configurable product's line is the parent with the variant bought; every other
     * visible line (simple, virtual, downloadable, a bundle) is its own product.
     *
     * @param object $order
     * @return array
     */
    public function metadata(object $order): array
    {
        $lines = [];
        foreach ((array)$order->getAllVisibleItems() as $item) {
            $variantId = null;
            if ((string)$item->getProductType() === 'configurable') {
                foreach ((array)$item->getChildrenItems() as $child) {
                    $variantId = (int)$child->getProductId();
                    break;
                }
            }
            $lines[] = [
                'product_id' => (int)$item->getProductId(),
                'variant_id' => $variantId,
                'name' => (string)$item->getName(),
                'quantity' => (float)$item->getQtyOrdered(),
                'price' => (float)$item->getPrice(),
            ];
        }
        return $this->body->purchase(
            (string)$order->getIncrementId(),
            (float)$order->getGrandTotal(),
            (string)$order->getOrderCurrencyCode(),
            $lines
        );
    }

    /**
     * Whether the order is paid outside Magento (no invoice event to wait for).
     *
     * @param object $order
     * @return bool
     */
    private function offline(object $order): bool
    {
        try {
            return (bool)$order->getPayment()->getMethodInstance()->isOffline();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Queue the purchase.
     *
     * @param object $order
     * @return bool
     */
    private function record(object $order): bool
    {
        try {
            $store = $order->getStore();
            $customerId = $order->getCustomerIsGuest() ? null : (int)$order->getCustomerId();
            return $this->recorder->order(
                (int)$store->getWebsiteId(),
                (string)$store->getBaseUrl(),
                $customerId,
                $this->metadata($order)
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
