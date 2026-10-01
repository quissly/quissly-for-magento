<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Config\Settings;

/**
 * The single source of truth for "is this website's connection working":
 * one real signed qsearch, classified per the fallback matrix. Used by the
 * admin Test Connection button now; the dashboard indicator reuses it later.
 */
class ConnectionService
{
    private const PROBE_QUERY = 'test';
    private const PROBE_USER_ID = 'quissly-connection-probe';

    /**
     * @param Settings $settings
     * @param HttpClient $httpClient
     * @param ResponseClassifier $classifier
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClient $httpClient,
        private readonly ResponseClassifier $classifier,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run the probe for a website scope.
     *
     * @param int|null $websiteId null = current context
     * @return array{code: string, message: string, http_status: int|null}
     */
    public function test(?int $websiteId = null): array
    {
        $token = $this->settings->apiToken($websiteId);
        $privateKey = $this->settings->privateKeyPem($websiteId);
        if ($token === null || $privateKey === null) {
            return $this->result(ResponseClassifier::NOT_CONFIGURED, null);
        }

        try {
            $response = $this->httpClient->postV2(
                HttpClient::PATH_QSEARCH,
                [
                    // user_id KEY is required by the body model; body is extra="forbid"
                    // so send only modelled fields.
                    'query' => self::PROBE_QUERY,
                    'user_id' => self::PROBE_USER_ID,
                    'page_number' => 1,
                    'page_size' => 1,
                    'channel' => 'web',
                ],
                $token,
                $privateKey,
                $this->settings->environment($websiteId),
                true,
                null,
                $this->settings->apiBaseUrl($websiteId)
            );
        } catch (SignerException $e) {
            // Never send an empty signature; a broken key is a configuration state.
            $this->logger->error('[quissly] signer failure during connection test: ' . $e->getMessage());
            return $this->result(ResponseClassifier::NOT_CONFIGURED, null);
        }

        $code = $this->classifier->classify(
            $response['status'],
            $response['content_type'],
            $response['body'],
            $response['server_time'],
            time()
        );
        // Status codes and classification only - never bodies, queries, or secrets.
        $this->logger->info(
            sprintf('[quissly] connection test: http=%d code=%s', $response['status'], $code)
        );
        return $this->result($code, $response['status'] ?: null);
    }

    /**
     * Shape the outcome payload for callers.
     *
     * @param string $code
     * @param int|null $httpStatus
     * @return array{code: string, message: string, http_status: int|null}
     */
    private function result(string $code, ?int $httpStatus): array
    {
        return [
            'code' => $code,
            'message' => $this->classifier->describe($code),
            'http_status' => $httpStatus,
        ];
    }
}
