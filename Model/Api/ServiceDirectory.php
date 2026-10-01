<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Config\Settings;

/**
 * Looks up the ids of the services Quissly created for this store.
 *
 * The QChat agent id is one of them - it is simply the qchat service's id, a
 * fact the merchant has no way of knowing and no reason to type. Provisioning
 * creates the service but does not return its id, so it is fetched here
 * afterwards and written to config like any other credential.
 *
 * Authenticated with a panel session rather than the signed search API: this
 * endpoint lives on the console host and expects a bearer JWT.
 */
class ServiceDirectory
{
    private const PATH_PROJECT_SERVICES = '/api/v1/services/project/';

    /** An admin waiting on a page, not a shopper waiting on results. */
    private const TIMEOUT_SECONDS = 10;

    public const SLUG_QCHAT = 'qchat';

    /**
     * @param Settings $settings
     * @param PanelSession $panelSession
     * @param CurlFactory $curlFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly PanelSession $panelSession,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The QChat agent id for this website, or null if it cannot be determined.
     *
     * Null is a normal outcome - the tenant may have no qchat service - and the
     * caller must treat it as "chat not available", never as an error worth
     * showing a merchant.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function qchatAgentId(?int $websiteId = null): ?string
    {
        return $this->serviceId(self::SLUG_QCHAT, $websiteId);
    }

    /**
     * The qsearch service's quissly_service_link: the namespace of Quissly's own
     * product ids. The chat widget names a product by uuid5(link, "<product id>"),
     * so the chat cart bridge (Model/Cart/ChatIdMap) needs it to map the widget's
     * ids back to Magento ids. LIVE-CONFIRMED 2026-09-24 on the CS-Cart and
     * WooCommerce test stores.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function qsearchNamespace(?int $websiteId = null): ?string
    {
        return $this->serviceField('qsearch', 'quissly_service_link', $websiteId);
    }

    /**
     * Find the id of the service with the given type slug.
     *
     * @param string $slug
     * @param int|null $websiteId
     * @return string|null
     */
    public function serviceId(string $slug, ?int $websiteId = null): ?string
    {
        return $this->serviceField($slug, 'id', $websiteId);
    }

    /**
     * One field of the service with the given type slug.
     *
     * @param string $slug
     * @param string $field
     * @param int|null $websiteId
     * @return string|null
     */
    public function serviceField(string $slug, string $field, ?int $websiteId = null): ?string
    {
        $projectId = $this->settings->projectId($websiteId);
        if ($projectId === null) {
            return null;
        }

        $session = $this->panelSession->open($websiteId);
        if (!($session['ok'] ?? false)) {
            $this->logger->info('[quissly] service lookup skipped: no panel session');
            return null;
        }

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->addHeader('Authorization', 'Bearer ' . $session['access_token']);

        $url = rtrim($this->settings->consoleUrl($websiteId), '/')
            . self::PATH_PROJECT_SERVICES . rawurlencode($projectId);

        try {
            $curl->get($url);
        } catch (\Throwable $e) {
            $this->logger->error('[quissly] service lookup transport failure: ' . $e->getMessage());
            return null;
        }

        $status = (int)$curl->getStatus();
        if ($status !== 200) {
            $this->logger->info(sprintf('[quissly] service lookup http=%d', $status));
            return null;
        }

        $decoded = json_decode((string)$curl->getBody(), true);
        if (!is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $service) {
            if (!is_array($service)) {
                continue;
            }
            if (($service['service_type_slug'] ?? null) === $slug && !empty($service[$field])) {
                return (string)$service[$field];
            }
        }

        return null;
    }
}
