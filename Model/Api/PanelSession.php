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
 * Opens a Quissly admin-panel session for the embedded console.
 *
 * The store's API key is exchanged SERVER-SIDE for a short-lived panel session,
 * so the key itself never reaches the browser. Only the resulting tokens do.
 *
 * Deliberately not routed through HttpClient: this endpoint takes no signature,
 * lives on the console host rather than the search API, and a failure here is a
 * broken admin page rather than a degraded storefront.
 */
class PanelSession
{
    private const PATH_SERVICE_LOGIN = '/api/v1/auth/service-login/store';

    /** An admin waiting on a page; not the 2 s shopper budget, not the 25 s media one. */
    private const TIMEOUT_SECONDS = 10;

    /**
     * @param Settings $settings
     * @param CurlFactory $curlFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Exchange the store key for panel tokens.
     *
     * @param int|null $websiteId
     * @return array Shape: {ok: bool, access_token: string, refresh_token: string, error: string}
     */
    public function open(?int $websiteId = null): array
    {
        $projectId = $this->settings->projectId($websiteId);
        $email = $this->settings->accountEmail($websiteId);
        $apiKey = $this->settings->apiToken($websiteId);

        if ($projectId === null || $email === null || $apiKey === null) {
            return $this->failure('not_configured');
        }

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->addHeader('Content-Type', 'application/json');

        $body = json_encode([
            'project_id' => $projectId,
            'api_key' => $apiKey,
            'email' => $email,
            'platform' => 'magento',
        ], JSON_UNESCAPED_SLASHES) ?: '{}';

        try {
            $curl->post($this->settings->consoleUrl($websiteId) . self::PATH_SERVICE_LOGIN, $body);
        } catch (\Throwable $e) {
            $this->logger->error('[quissly] panel session transport failure: ' . $e->getMessage());
            return $this->failure('transport_error');
        }

        $status = (int)$curl->getStatus();
        if ($status !== 200) {
            // 401 here is usually the provider check: the configured address
            // belongs to a person's own Quissly login rather than the account
            // provisioning created for this store.
            $this->logger->info(sprintf('[quissly] panel session http=%d', $status));
            return $this->failure($status === 401 ? 'rejected' : 'unexpected');
        }

        $decoded = json_decode((string)$curl->getBody(), true);
        $access = is_array($decoded) ? (string)($decoded['access_token'] ?? '') : '';
        $refresh = is_array($decoded) ? (string)($decoded['refresh_token'] ?? '') : '';

        if ($access === '' || $refresh === '') {
            $this->logger->error('[quissly] panel session 200 without tokens');
            return $this->failure('unexpected');
        }

        $this->logger->info('[quissly] panel session opened');
        return [
            'ok' => true,
            'access_token' => $access,
            'refresh_token' => $refresh,
            'error' => '',
        ];
    }

    /**
     * A failed session, shaped like a successful one.
     *
     * @param string $error
     * @return array
     */
    private function failure(string $error): array
    {
        return ['ok' => false, 'access_token' => '', 'refresh_token' => '', 'error' => $error];
    }
}
