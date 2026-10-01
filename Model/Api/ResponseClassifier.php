<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

/**
 * Classifies a Quissly API outcome per the fallback matrix
 * (live-confirmed behaviors).
 *
 * Pure PHP so it is unit-testable without Magento. Key rules:
 *  - 403 with a JSON "detail" body is the APPLICATION (auth / billing / feature
 *    gate); 403 with an HTML body is the EDGE (infrastructure) - transient.
 *  - A bare 401 usually means an unregistered public key - or server clock
 *    drift, which produces the identical symptom (±60 s signing window).
 */
class ResponseClassifier
{
    public const OK = 'ok';
    public const NOT_CONFIGURED = 'not_configured';
    public const AUTH_ERROR = 'auth_error';
    public const CLOCK_SKEW_SUSPECTED = 'clock_skew_suspected';
    public const PAYMENT_REQUIRED = 'payment_required';
    public const FORBIDDEN = 'forbidden';
    public const EDGE_BLOCKED = 'edge_blocked';
    public const INVALID_REQUEST = 'invalid_request';
    public const RATE_LIMITED = 'rate_limited';
    public const SERVER_ERROR = 'server_error';
    public const TRANSPORT_ERROR = 'transport_error';
    public const UNEXPECTED = 'unexpected';

    /**
     * Allowed absolute clock drift before we suspect skew, in seconds.
     * Matches the backend's v2 signing window.
     */
    private const SKEW_THRESHOLD_SECONDS = 60;

    /**
     * Classify an HTTP outcome. A transport failure is status 0.
     *
     * @param int $status HTTP status, 0 on transport failure
     * @param string $contentType Response Content-Type header ('' if none)
     * @param string $body Response body ('' if none)
     * @param int|null $serverTimestamp Server "Date" header as unix time, null if absent
     * @param int|null $localTimestamp Local unix time at response, null to skip skew check
     * @return string One of the class constants
     */
    public function classify(
        int $status,
        string $contentType,
        string $body,
        ?int $serverTimestamp = null,
        ?int $localTimestamp = null
    ): string {
        if ($status === 0) {
            return self::TRANSPORT_ERROR;
        }
        if ($status >= 200 && $status < 300) {
            return self::OK;
        }
        $isJson = str_contains(strtolower($contentType), 'json')
            || (is_array(json_decode($body, true)) && $body !== '');

        if ($status === 401) {
            if ($this->clockDriftSeconds($serverTimestamp, $localTimestamp) > self::SKEW_THRESHOLD_SECONDS) {
                return self::CLOCK_SKEW_SUSPECTED;
            }
            return self::AUTH_ERROR;
        }
        if ($status === 402) {
            return self::PAYMENT_REQUIRED;
        }
        if ($status === 429) {
            return self::RATE_LIMITED;
        }
        if ($status === 403) {
            if (!$isJson) {
                return self::EDGE_BLOCKED;
            }
            if ($this->clockDriftSeconds($serverTimestamp, $localTimestamp) > self::SKEW_THRESHOLD_SECONDS) {
                return self::CLOCK_SKEW_SUSPECTED;
            }
            return self::FORBIDDEN;
        }
        if ($status === 400 || $status === 404 || $status === 422) {
            return self::INVALID_REQUEST;
        }
        if ($status >= 500) {
            return self::SERVER_ERROR;
        }
        return self::UNEXPECTED;
    }

    /**
     * Human-readable admin message for a classification.
     *
     * @param string $code
     * @return string
     */
    public function describe(string $code): string
    {
        return match ($code) {
            self::OK => 'Connected. Quissly answered the signed test request.',
            self::NOT_CONFIGURED => 'Not configured: enter the API token and generate keys first.',
            self::AUTH_ERROR => 'Authentication failed. Most common cause: the public key is not '
                . 'registered with Quissly yet (register it in your Quissly console), or the API '
                . 'token is wrong.',
            self::CLOCK_SKEW_SUSPECTED => 'Authentication failed AND this server\'s clock disagrees '
                . 'with Quissly\'s by more than 60 seconds. Fix the server clock (NTP) before '
                . 'debugging credentials - signed requests are only valid for ±60 seconds.',
            self::PAYMENT_REQUIRED => 'Quissly reports a billing problem with this account (HTTP 402). '
                . 'Check your Quissly console.',
            self::FORBIDDEN => 'Quissly refused the request (HTTP 403 from the application). Check the '
                . 'account status in your Quissly console.',
            self::EDGE_BLOCKED => 'Blocked before reaching Quissly (HTTP 403 from the network edge). '
                . 'This is usually infrastructure or IP filtering - not a credentials problem.',
            self::INVALID_REQUEST => 'Quissly rejected the request as invalid - this is a module bug '
                . 'or an API change. Check var/log/quissly.log.',
            self::RATE_LIMITED => 'Quissly rate-limited the request (HTTP 429). The sync backs off '
                . 'and retries automatically.',
            self::SERVER_ERROR => 'Quissly returned a server error (5xx). Usually transient - retry '
                . 'shortly.',
            self::TRANSPORT_ERROR => 'Could not reach api.quissly.com (timeout or network failure). '
                . 'Check outbound HTTPS connectivity.',
            default => 'Unexpected response from Quissly.',
        };
    }

    /**
     * Absolute drift between server and local clocks in seconds; 0 when unknown.
     *
     * @param int|null $serverTimestamp
     * @param int|null $localTimestamp
     * @return int
     */
    private function clockDriftSeconds(?int $serverTimestamp, ?int $localTimestamp): int
    {
        if ($serverTimestamp === null || $localTimestamp === null || $serverTimestamp <= 0) {
            return 0;
        }
        return abs($serverTimestamp - $localTimestamp);
    }
}
