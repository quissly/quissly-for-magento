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
 * v1 catalog operations for a website's tenant: add / update / delete. Returns
 * the outcome of the send itself; the operation's status is not read - a 2xx is
 * the batch delivered, as in the Quissly Shopify app (SyncWorker).
 */
class CatalogClient
{
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
     * Send an add (POST) or update (PUT) batch.
     *
     * @param string $method POST|PUT
     * @param array $records Map of id => ProductItem record (ids STRINGS)
     * @param int|null $websiteId
     * @return array{code: string, operation_id: string|null}
     * @throws SignerException
     */
    public function sendBatch(string $method, array $records, ?int $websiteId): array
    {
        [$token, $key] = $this->credentials($websiteId);
        if ($token === null) {
            return ['code' => ResponseClassifier::NOT_CONFIGURED, 'operation_id' => null];
        }
        $firstId = (string)array_key_first($records);
        $response = $this->httpClient->requestV1Catalog(
            $method,
            $records,
            $firstId,
            $token,
            $key,
            $this->settings->environment($websiteId),
            $this->settings->apiBaseUrl($websiteId)
        );
        return $this->mutationOutcome($response, $method, count($records));
    }

    /**
     * Send a delete batch.
     *
     * @param string[] $ids Product ids (string form)
     * @param int|null $websiteId
     * @return array{code: string, operation_id: string|null}
     * @throws SignerException
     */
    public function sendDeletes(array $ids, ?int $websiteId): array
    {
        [$token, $key] = $this->credentials($websiteId);
        if ($token === null) {
            return ['code' => ResponseClassifier::NOT_CONFIGURED, 'operation_id' => null];
        }
        // Delete body: array of OBJECTS (a flat string list 422s).
        $data = array_map(static fn (string $id): array => ['id' => $id], array_values($ids));
        $response = $this->httpClient->requestV1Catalog(
            'DELETE',
            $data,
            (string)$ids[array_key_first($ids)],
            $token,
            $key,
            $this->settings->environment($websiteId),
            $this->settings->apiBaseUrl($websiteId)
        );
        return $this->mutationOutcome($response, 'DELETE', count($ids));
    }

    /**
     * Classify a mutation response.
     *
     * @param array $response
     * @param string $method
     * @param int $count
     * @return array{code: string, operation_id: string|null}
     */
    private function mutationOutcome(array $response, string $method, int $count): array
    {
        $code = $this->classifier->classify(
            $response['status'],
            $response['content_type'],
            $response['body'],
            $response['server_time'],
            time()
        );
        $operationId = null;
        if ($code === ResponseClassifier::OK) {
            $decoded = json_decode($response['body'], true);
            $operationId = is_array($decoded) ? ($decoded['operation_id'] ?? null) : null;
        }
        // ids/counts/status only - never payloads.
        $this->logger->info(sprintf(
            '[quissly] catalog %s items=%d http=%d code=%s op=%s',
            $method,
            $count,
            $response['status'],
            $code,
            $operationId ?? '-'
        ));
        return ['code' => $code, 'operation_id' => $operationId];
    }

    /**
     * Resolve credentials for a scope.
     *
     * @param int|null $websiteId
     * @return array{0: string|null, 1: string|null}
     */
    private function credentials(?int $websiteId): array
    {
        $token = $this->settings->apiToken($websiteId);
        $key = $this->settings->privateKeyPem($websiteId);
        return $token !== null && $key !== null ? [$token, $key] : [null, null];
    }
}
