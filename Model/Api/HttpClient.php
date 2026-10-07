<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Signed transport to api.quissly.com.
 *
 * One constant per signed endpoint path (the path is part of the v2
 * signature). X-Platform: magento is accepted since backend
 * commit 5181564a (live-verified 2026-08-14).
 */
class HttpClient
{
    /**
     * Default API host. Callers pass an explicit base URL (Settings::apiBaseUrl)
     * so a website can be pointed at another Quissly deployment; this remains
     * the fallback and the documented production host.
     */
    public const BASE_URL = 'https://api.quissly.com';
    public const PATH_QSEARCH = '/v2beta/qsearch';
    public const PATH_QUICK = '/v2beta/quick';
    public const PATH_QIMAGE = '/v2beta/qimage';
    public const PATH_CATALOG = '/v1beta/catalog';
    public const X_PLATFORM = 'magento';

    /** Search-family timeout budget in seconds (2 s strict). */
    private const SEARCH_TIMEOUT_SECONDS = 2;

    /**
     * Voice/image searches include server-side transcription/embedding (Gemini
     * calls) - far slower than text search. The shopper is on an explicit
     * "search by voice/photo" flow, so a generous budget is correct; the 2 s
     * interception budget must never apply here.
     */
    public const MEDIA_TIMEOUT_SECONDS = 25;
    /** Admin/diagnostic calls may wait longer than the shopper path. */
    private const DIAGNOSTIC_TIMEOUT_SECONDS = 8;
    /** Catalog batches carry large payloads; background path, generous budget. */
    private const CATALOG_TIMEOUT_SECONDS = 60;

    /**
     * @param CurlFactory $curlFactory
     * @param Signer $signer
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Signer $signer
    ) {
    }

    /**
     * Signed v2 POST. Returns status/body/content-type/server "Date" as unix
     * time; transport failure = status 0, never an exception (the fallback
     * matrix owns failure semantics, not the transport).
     *
     * @param string $path One of the PATH_* constants
     * @param array $body Request body - modelled fields only (extra="forbid")
     * @param string $token
     * @param string $privateKeyPem
     * @param string $environment
     * @param bool $diagnostic true = longer timeout (admin test-connection)
     * @param int|null $timeoutSeconds explicit budget, overriding both defaults
     *                                 (voice/image use MEDIA_TIMEOUT_SECONDS)
     * @param string|null $baseUrl API host override (Settings::apiBaseUrl)
     * @return array{status: int, body: string, content_type: string, server_time: int|null}
     * @throws SignerException
     */
    public function postV2(
        string $path,
        array $body,
        string $token,
        string $privateKeyPem,
        string $environment,
        bool $diagnostic = false,
        ?int $timeoutSeconds = null,
        ?string $baseUrl = null
    ): array {
        $timestampMs = $this->signer->timestampMs();
        $nonce = $this->signer->nonce();
        $signature = $this->signer->signV2($privateKeyPem, 'POST', $path, $timestampMs, $nonce);

        $curl = $this->curlFactory->create();
        $curl->setTimeout(
            $timeoutSeconds ?? ($diagnostic ? self::DIAGNOSTIC_TIMEOUT_SECONDS : self::SEARCH_TIMEOUT_SECONDS)
        );
        $curl->addHeader('Authorization', 'Bearer ' . $token);
        $curl->addHeader('X-Signature', $signature);
        $curl->addHeader('X-Environment', $environment);
        $curl->addHeader('X-Timestamp', $timestampMs);
        $curl->addHeader('X-Nonce', $nonce);
        $curl->addHeader('X-Platform', self::X_PLATFORM);
        $curl->addHeader('Content-Type', 'application/json');

        try {
            $curl->post(($baseUrl ?: self::BASE_URL) . $path, json_encode($body, JSON_UNESCAPED_SLASHES) ?: '{}');
        } catch (\Throwable $e) {
            return ['status' => 0, 'body' => $e->getMessage(), 'content_type' => '', 'server_time' => null];
        }

        return [
            'status' => (int)$curl->getStatus(),
            'body' => (string)$curl->getBody(),
            'content_type' => $this->header($curl, 'content-type'),
            'server_time' => $this->serverTime($curl),
        ];
    }

    /**
     * Signed v1 catalog mutation (POST=add, PUT=update, DELETE=delete).
     *
     * V1 scheme: sign "{firstId}.{isoTimestamp}",
     * timestamp travels IN THE BODY (space separator), no X-Timestamp/X-Nonce.
     *
     * @param string $method POST|PUT|DELETE
     * @param mixed $data Records map (add/update) or [{"id":...}] list (delete)
     * @param string $signPayload First product id of the batch (string form)
     * @param string $token
     * @param string $privateKeyPem
     * @param string $environment
     * @param string|null $baseUrl API host override (Settings::apiBaseUrl)
     * @return array{status: int, body: string, content_type: string, server_time: int|null}
     * @throws SignerException
     */
    public function requestV1Catalog(
        string $method,
        $data,
        string $signPayload,
        string $token,
        string $privateKeyPem,
        string $environment,
        ?string $baseUrl = null
    ): array {
        $timestamp = $this->signer->timestampIsoSpace();
        $signature = $this->signer->signV1($privateKeyPem, $signPayload, $timestamp);
        $body = json_encode(
            ['data' => $data, 'service' => 'search', 'timestamp' => $timestamp],
            JSON_UNESCAPED_SLASHES
        ) ?: '{}';

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::CATALOG_TIMEOUT_SECONDS);
        $curl->addHeader('Authorization', 'Bearer ' . $token);
        $curl->addHeader('X-Signature', $signature);
        $curl->addHeader('X-Environment', $environment);
        $curl->addHeader('X-Platform', self::X_PLATFORM);
        $curl->addHeader('Content-Type', 'application/json');

        try {
            // Curl::post/put cover POST/PUT; DELETE needs the custom option.
            if ($method === 'POST') {
                $curl->post(($baseUrl ?: self::BASE_URL) . self::PATH_CATALOG, $body);
            } else {
                $curl->setOption(CURLOPT_CUSTOMREQUEST, $method);
                $curl->post(($baseUrl ?: self::BASE_URL) . self::PATH_CATALOG, $body);
            }
        } catch (\Throwable $e) {
            return ['status' => 0, 'body' => $e->getMessage(), 'content_type' => '', 'server_time' => null];
        }

        return [
            'status' => (int)$curl->getStatus(),
            'body' => (string)$curl->getBody(),
            'content_type' => $this->header($curl, 'content-type'),
            'server_time' => $this->serverTime($curl),
        ];
    }

    /**
     * Case-insensitive response header lookup.
     *
     * @param Curl $curl
     * @param string $name
     * @return string
     */
    private function header(Curl $curl, string $name): string
    {
        foreach ($curl->getHeaders() as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return is_array($value) ? (string)reset($value) : (string)$value;
            }
        }
        return '';
    }

    /**
     * Server "Date" header as unix time, for the clock-skew diagnostic.
     *
     * @param Curl $curl
     * @return int|null
     */
    private function serverTime(Curl $curl): ?int
    {
        $date = $this->header($curl, 'date');
        if ($date === '') {
            return null;
        }
        $time = strtotime($date);
        return $time === false ? null : $time;
    }
}
