<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Events;

use Laminas\Uri\UriFactory;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Search\Shopper;

/**
 * Records a shopping event for Quissly's analytics: checks it may be sent, then queues it.
 *
 * Sent only while the website's "Shopping activity" switch is on (default on) and the
 * website is connected. A shopper's own event carries the same user_id a search does
 * (customer:<id> / guest:<uuid>, Model/Search/Shopper) and is NOT recorded for a guest
 * who has not accepted the store's cookie notice - the Shopify app drops its tracker's
 * events without analytics consent too. A paid order is the store's own record and is
 * always sent, as customer:<id> or with no user_id (Shopify's order webhook does the same).
 *
 * Never throws: an event is not worth a shopper's failed add to cart or a failed order.
 */
class Recorder
{
    /**
     * @param Settings $settings
     * @param Shopper $shopper
     * @param EventBody $body
     * @param EventQueue $queue
     * @param StoreManagerInterface $storeManager
     * @param IdentityGeneratorInterface $ids
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly Shopper $shopper,
        private readonly EventBody $body,
        private readonly EventQueue $queue,
        private readonly StoreManagerInterface $storeManager,
        private readonly IdentityGeneratorInterface $ids,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether events are sent for this website.
     *
     * @param int $websiteId
     * @return bool
     */
    public function enabled(int $websiteId): bool
    {
        return $this->settings->isEventsEnabled($websiteId) && $this->settings->isConfigured($websiteId);
    }

    /**
     * Record the current shopper's event (a storefront request).
     *
     * @param string $type EventBody::VIEW | SEARCH | ADD_TO_CART | WISHLIST
     * @param array $metadata from EventBody
     * @return bool recorded
     */
    public function shopper(string $type, array $metadata): bool
    {
        try {
            $store = $this->storeManager->getStore();
            $websiteId = (int)$store->getWebsiteId();
            if (!$this->enabled($websiteId)) {
                return false;
            }
            $fields = $this->shopper->forSearch();
            if (($fields['user_id'] ?? null) === null) {
                return false;
            }
            $now = time();
            $this->queue->add($websiteId, $this->body->envelope(
                $type,
                $metadata,
                $fields['user_id'],
                $fields,
                $this->host((string)$store->getBaseUrl()),
                EventBody::SOURCE_STOREFRONT,
                $this->ids->generateId(),
                $now
            ), $now);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('[quissly] event not recorded type=' . $type . ' error=' . get_class($e));
            return false;
        }
    }

    /**
     * Record a paid order.
     *
     * @param int $websiteId the order's website
     * @param string $baseUrl the order's store URL
     * @param int|null $customerId null = a guest order
     * @param array $metadata EventBody::purchase()
     * @return bool recorded
     */
    public function order(int $websiteId, string $baseUrl, ?int $customerId, array $metadata): bool
    {
        try {
            if (!$this->enabled($websiteId)) {
                return false;
            }
            $now = time();
            $this->queue->add($websiteId, $this->body->envelope(
                EventBody::PURCHASE,
                $metadata,
                $customerId !== null && $customerId > 0 ? Shopper::CUSTOMER_PREFIX . $customerId : null,
                [],
                $this->host($baseUrl),
                EventBody::SOURCE_ORDER,
                $this->ids->generateId(),
                $now
            ), $now);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('[quissly] purchase not recorded error=' . get_class($e));
            return false;
        }
    }

    /**
     * The store's host ("shop.example.com") - Shopify sends its myshopify domain.
     *
     * @param string $baseUrl
     * @return string
     */
    private function host(string $baseUrl): string
    {
        try {
            return strtolower((string)UriFactory::factory($baseUrl)->getHost());
        } catch (\Throwable $e) {
            return '';
        }
    }
}
