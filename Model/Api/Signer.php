<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

/**
 * The two Quissly signing schemes. Pure PHP - no Magento dependencies - so the
 * golden-vector unit tests run without the framework.
 *
 * LIVE-CONFIRMED against the deployed API (2026-08-14):
 *  - v2 (search family): sign "METHOD\nPATH\nTS_MS\nNONCE",
 *    RSA-SHA256 PKCS#1 v1.5, base64. The SAME timestamp and nonce must be sent
 *    as X-Timestamp / X-Nonce. The path is part of the signed string and must
 *    match the request URL byte-for-byte.
 *  - v1 (catalog): sign "{payload}.{timestamp}" where payload is the first
 *    product id (mutations) or the operation_id (status). Mutation body
 *    timestamps use a space separator; status query timestamps use "T" and the
 *    identical string is both the signed payload and the query parameter.
 *
 * DO NOT CONFLATE THE SCHEMES.
 */
class Signer
{
    /**
     * Sign the v2 canonical string.
     *
     * @param string $privateKeyPem
     * @param string $method
     * @param string $path
     * @param string $timestampMs
     * @param string $nonce
     * @return string base64 signature
     * @throws SignerException
     */
    public function signV2(
        string $privateKeyPem,
        string $method,
        string $path,
        string $timestampMs,
        string $nonce
    ): string {
        $message = implode("\n", [$method, $path, $timestampMs, $nonce]);
        return $this->sign($privateKeyPem, $message);
    }

    /**
     * Sign the v1 canonical string.
     *
     * @param string $privateKeyPem
     * @param string $payload
     * @param string $isoTimestamp
     * @return string base64 signature
     * @throws SignerException
     */
    public function signV1(string $privateKeyPem, string $payload, string $isoTimestamp): string
    {
        return $this->sign($privateKeyPem, $payload . '.' . $isoTimestamp);
    }

    /**
     * Current v2 timestamp: integer milliseconds since epoch, as a string.
     *
     * @return string
     */
    public function timestampMs(): string
    {
        return (string)(int)round(microtime(true) * 1000);
    }

    /**
     * V1 mutation-body timestamp: microsecond precision, SPACE separator, UTC offset.
     *
     * @return string
     */
    public function timestampIsoSpace(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP');
    }

    /**
     * V1 status-query timestamp: microsecond precision, "T" separator (ISO 8601).
     *
     * @return string
     */
    public function timestampIso8601(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * RFC 4122 v4 nonce.
     *
     * @return string
     */
    public function nonce(): string
    {
        $hex = bin2hex(random_bytes(16));
        // Force RFC 4122 version (4) and variant (8-b) nibbles.
        $hex = substr_replace($hex, '4', 12, 1);
        $hex = substr_replace($hex, dechex(8 | (hexdec($hex[16]) & 0x3)), 16, 1);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }

    /**
     * RSA-SHA256 PKCS#1 v1.5, base64. Throws on any failure.
     *
     * @param string $privateKeyPem
     * @param string $message
     * @return string
     * @throws SignerException
     */
    private function sign(string $privateKeyPem, string $message): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new SignerException('Unable to load the signing private key.');
        }
        $signature = '';
        if (!openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new SignerException('Signing failed: ' . (string)openssl_error_string());
        }
        return base64_encode($signature);
    }
}
