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
 * v1 catalog operations for a website's tenant: add / update / delete + status
 * polling. Returns decoded outcomes; interpretation of per-item results is the
 * StatusClassifier's job (R3 rules), not this transport wrapper's.
 */
class CatalogClient
{
    /**
     * The POST returns an operation id immediately; what takes time is the
     * operation reaching a terminal status. Polling every 5s for 24 tries gave
     * up after two minutes, which a 50-item batch regularly outran - the batch
     * was then counted failed and requeued, the retry found everything already
     * ingested, and it was re-routed to an update. That is where the phantom
     * "update" operations came from. Fewer, longer waits: 10 x 30s = 5 minutes.
     */
    private const MAX_STATUS_POLLS = 10;
    private const STATUS_POLL_DELAY_SECONDS = 30;

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
     * One status request, no waiting: the decoded body whatever its state, or
     * null when the request itself failed. Used to check on an operation kept
     * from an earlier run (PendingOperations) without spending the poll budget.
     *
     * @param string $operationId
     * @param int|null $websiteId
     * @return array|null
     * @throws SignerException
     */
    public function checkStatus(string $operationId, ?int $websiteId): ?array
    {
        [$token, $key] = $this->credentials($websiteId);
        if ($token === null) {
            return null;
        }
        $response = $this->httpClient->getV1CatalogStatus(
            $operationId,
            $token,
            $key,
            $this->settings->environment($websiteId),
            $this->settings->apiBaseUrl($websiteId)
        );
        if ($response['status'] !== 200) {
            $this->logger->info(sprintf(
                '[quissly] catalog status check http=%d op=%s',
                $response['status'],
                $operationId
            ));
            return null;
        }
        $decoded = json_decode($response['body'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Poll an operation to a terminal state (bounded).
     *
     * @param string $operationId
     * @param int|null $websiteId
     * @return array|null Decoded terminal status body, null when never terminal/reachable
     * @throws SignerException
     */
    public function pollStatus(string $operationId, ?int $websiteId): ?array
    {
        [$token, $key] = $this->credentials($websiteId);
        if ($token === null) {
            return null;
        }
        $environment = $this->settings->environment($websiteId);
        for ($attempt = 0; $attempt < self::MAX_STATUS_POLLS; $attempt++) {
            if ($attempt > 0) {
                // Background cron path; bounded waiting is acceptable here.
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                sleep(self::STATUS_POLL_DELAY_SECONDS);
            }
            $response = $this->httpClient->getV1CatalogStatus(
                $operationId,
                $token,
                $key,
                $environment,
                $this->settings->apiBaseUrl($websiteId)
            );
            if ($response['status'] !== 200) {
                $this->logger->info(sprintf(
                    '[quissly] catalog status poll http=%d op=%s',
                    $response['status'],
                    $operationId
                ));
                continue;
            }
            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded)) {
                continue;
            }
            $state = strtolower((string)($decoded['status'] ?? ''));
            if (in_array($state, \Quissly\Search\Model\Sync\StatusClassifier::TERMINAL_STATES, true)) {
                return $decoded;
            }
        }

        // Giving up used to be silent, which hid the single most informative
        // failure in the sync path: the batch had almost certainly been
        // ingested, we simply stopped waiting for the verdict. The caller then
        // counts it failed and requeues it, and the retry comes back "already
        // exists" - which is where the phantom update operations come from.
        $this->logger->info(sprintf(
            '[quissly] catalog status poll gave up after %ds op=%s (never reached a terminal state)',
            self::MAX_STATUS_POLLS * self::STATUS_POLL_DELAY_SECONDS,
            $operationId
        ));

        return null;
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
