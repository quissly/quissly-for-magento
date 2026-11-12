<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Orders;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Shipping\Helper\Data as ShippingHelper;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Config\Settings;

/**
 * Quissly's order lookup for QChat, for one verified request (Controller/Orders/Lookup checked the
 * signature): the website connected to the request's project, the order with that number among the
 * website's store views, and - only when the email or phone is the order's own - its status, items and
 * tracking in the shape the engine reads for Shopify orders. Anything else is `not_found`, never saying
 * which part did not match.
 */
class OrderLookup
{
    public const MAX_ITEMS = 50;

    /**
     * @param StoreManagerInterface $storeManager
     * @param Settings $settings
     * @param CollectionFactory $orders
     * @param ShippingHelper $shipping
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Settings $settings,
        private readonly CollectionFactory $orders,
        private readonly ShippingHelper $shipping
    ) {
    }

    /**
     * The website whose connection is this project, with order status on; null when none.
     *
     * @param string $projectId
     * @return int|null
     */
    public function websiteFor(string $projectId): ?int
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            $id = (int)$website->getId();
            $own = strtolower((string)$this->settings->projectId($id));
            if ($own !== '' && hash_equals($own, strtolower($projectId))
                && $this->settings->isConfigured($id) && $this->settings->isOrderStatusEnabled($id)
            ) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The answer for a verified ask on this website.
     *
     * @param int $websiteId
     * @param string $number what the shopper gave
     * @param string $contact email or phone
     * @return array<string, mixed>
     */
    public function answer(int $websiteId, string $number, string $contact): array
    {
        $order = $this->find($websiteId, $number);
        if ($order === null) {
            return ['status' => 'not_found'];
        }
        $emails = [(string)$order->getCustomerEmail()];
        $phones = [];
        foreach ([$order->getBillingAddress(), $order->getShippingAddress()] as $address) {
            if ($address !== null) {
                $emails[] = (string)$address->getEmail();
                $phones[] = (string)$address->getTelephone();
            }
        }
        if (!LookupRules::contactMatches($contact, $emails, $phones)) {
            return ['status' => 'not_found'];
        }

        $ordered = 0.0;
        $shipped = 0.0;
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $ordered += (float)$item->getQtyOrdered();
            $shipped += (float)$item->getQtyShipped();
            if (count($items) < self::MAX_ITEMS) {
                $items[] = ['title' => strip_tags((string)$item->getName()), 'quantity' => (int)$item->getQtyOrdered()];
            }
        }
        $statuses = LookupRules::statuses(
            (string)$order->getState(),
            (float)$order->getGrandTotal(),
            (float)$order->getTotalPaid(),
            (float)$order->getTotalRefunded(),
            $ordered,
            $shipped
        );
        $created = strtotime((string)$order->getCreatedAt() . ' UTC');

        return [
            'status' => 'found',
            'order' => [
                'order_number' => (string)$order->getIncrementId(),
                'created_at' => $created ? gmdate('Y-m-d\TH:i:s\Z', $created) : '',
                'cancelled' => $statuses['cancelled'],
                'fulfillment_status' => $statuses['fulfillment_status'],
                'financial_status' => $statuses['financial_status'],
                'tracking' => $this->tracking($order),
                'items' => $items,
                'status_page_url' => $this->pageUrl($order),
            ],
        ];
    }

    /**
     * The order with this number on one of the website's store views.
     *
     * @param int $websiteId
     * @param string $number
     * @return Order|null
     */
    private function find(int $websiteId, string $number): ?Order
    {
        $candidates = LookupRules::candidates($number);
        $storeIds = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ((int)$store->getWebsiteId() === $websiteId) {
                $storeIds[] = (int)$store->getId();
            }
        }
        if ($candidates === [] || $storeIds === []) {
            return null;
        }
        $collection = $this->orders->create()
            ->addFieldToFilter('increment_id', ['in' => $candidates])
            ->addFieldToFilter('store_id', ['in' => $storeIds])
            ->setPageSize(2);
        foreach ($collection as $order) {
            if ($order instanceof Order && LookupRules::sameNumber($number, (string)$order->getIncrementId())) {
                return $order;
            }
        }

        return null;
    }

    /**
     * The shipments' tracking numbers; each opens Magento's own tracking page for the order.
     *
     * @param Order $order
     * @return array<int, array{company: string, number: string, url: string}>
     */
    private function tracking(Order $order): array
    {
        $out = [];
        $url = '';
        foreach ($order->getTracksCollection() as $track) {
            $number = (string)$track->getTrackNumber();
            if ($number === '') {
                continue;
            }
            if ($url === '') {
                $url = (string)$this->shipping->getTrackingPopupUrlBySalesModel($order);
            }
            $out[] = ['company' => (string)$track->getTitle(), 'number' => $number, 'url' => $url];
        }

        return $out;
    }

    /**
     * Where the shopper sees the order: their account's order page, or "Orders and Returns" for a guest.
     *
     * @param Order $order
     * @return string
     */
    private function pageUrl(Order $order): string
    {
        try {
            $store = $this->storeManager->getStore((int)$order->getStoreId());

            return $order->getCustomerId()
                ? (string)$store->getUrl('sales/order/view', ['order_id' => (int)$order->getId(), '_nosid' => true])
                : (string)$store->getUrl('sales/guest/form', ['_nosid' => true]);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
