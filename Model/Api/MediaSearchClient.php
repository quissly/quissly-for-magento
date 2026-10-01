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
 * Voice + image search client.
 *
 * Voice rides `/v2beta/qsearch` with an `audio` field (base64 WAV); image uses
 * `/v2beta/qimage` with an `image` field (base64 JPEG). Both return the same
 * `documents[]` shape as text qsearch, so ids validate through the shared
 * parser. user_id is the shopper's (Model/Search/Shopper) - the results are
 * handed on through a token, never cached into a page for someone else.
 * Backend gates today: voice = per-tenant 403, image = 503 - surfaced verbatim
 * so the proxy can render the right dark-state notice.
 */
class MediaSearchClient
{
    public const KIND_VOICE = 'voice';
    public const KIND_IMAGE = 'image';

    /**
     * @param Settings $settings
     * @param HttpClient $httpClient
     * @param ResponseClassifier $classifier
     * @param QsearchResponseParser $parser
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClient $httpClient,
        private readonly ResponseClassifier $classifier,
        private readonly QsearchResponseParser $parser,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run a voice or image search.
     *
     * Returns a classifier code, validated ids, and (voice) the transcribed
     * query to show as the visible search term.
     *
     * @param string $kind self::KIND_VOICE|self::KIND_IMAGE
     * @param string $mediaBase64 base64 WAV (voice) or JPEG (image)
     * @param int|null $websiteId
     * @param string|null $userId who is searching (Shopper::userId())
     * @return array{code: string, ids: int[], query: string, status: int}
     */
    public function search(string $kind, string $mediaBase64, ?int $websiteId, ?string $userId = null): array
    {
        $token = $this->settings->apiToken($websiteId);
        $privateKey = $this->settings->privateKeyPem($websiteId);
        if ($token === null || $privateKey === null) {
            return $this->empty(ResponseClassifier::NOT_CONFIGURED, 0);
        }

        [$path, $body] = $this->request($kind, $mediaBase64, $userId);

        try {
            $response = $this->httpClient->postV2(
                $path,
                $body,
                $token,
                $privateKey,
                $this->settings->environment($websiteId),
                false,
                HttpClient::MEDIA_TIMEOUT_SECONDS,
                $this->settings->apiBaseUrl($websiteId)
            );
        } catch (SignerException $e) {
            $this->logger->error('[quissly] signer failure on ' . $kind . ' path: ' . $e->getMessage());
            return $this->empty(ResponseClassifier::NOT_CONFIGURED, 0);
        }

        $code = $this->classifier->classify(
            $response['status'],
            $response['content_type'],
            $response['body'],
            $response['server_time'],
            time()
        );
        if ($code !== ResponseClassifier::OK) {
            // Expected while dark: voice 403 (per-tenant flag), image 503 (service off).
            $this->logger->info(sprintf('[quissly] %s http=%d code=%s', $kind, $response['status'], $code));
            return $this->empty($code, (int)$response['status']);
        }

        $parsed = $this->parser->parse($response['body']);
        if ($parsed['malformed']) {
            $this->logger->error('[quissly] ' . $kind . ' 200 with unparseable body');
            return $this->empty(ResponseClassifier::UNEXPECTED, 200);
        }
        if ($parsed['dropped'] > 0) {
            $this->logger->warning(sprintf('[quissly] %s dropped %d malformed ids', $kind, $parsed['dropped']));
        }
        $this->logger->info(sprintf('[quissly] %s http=200 code=ok ids=%d', $kind, count($parsed['ids'])));
        return [
            'code' => ResponseClassifier::OK,
            'ids' => $parsed['ids'],
            // The parser extracts top_variant_id on every path; dropping it
            // here is what left voice and image showing the parent's picture
            // while the same query typed showed the matched variant.
            'variants' => $parsed['variants'],
            'query' => $this->transcription($response['body']),
            'status' => 200,
        ];
    }

    /**
     * Endpoint path + request body for the given media kind.
     *
     * @param string $kind
     * @param string $mediaBase64
     * @param string|null $userId
     * @return array{0: string, 1: array}
     */
    private function request(string $kind, string $mediaBase64, ?string $userId): array
    {
        if ($kind === self::KIND_IMAGE) {
            // No body timestamp. The only timestamp that means anything here is
            // X-Timestamp, which postV2 sends and which IS covered by the v2
            // signature ("POST\n{path}\n{ts_ms}\n{nonce}"). A second one in the
            // body was never signed, so it proved nothing and could disagree with
            // the header it sat next to.
            return [HttpClient::PATH_QIMAGE, [
                'image' => $mediaBase64,
                'user_id' => $userId,
                'channel' => 'web',
            ]];
        }
        return [HttpClient::PATH_QSEARCH, [
            'audio' => $mediaBase64,
            'user_id' => $userId,
            'page_number' => 1,
            'page_size' => 24,
            'sort_by' => 0,
            'sort_type' => 2,
            'channel' => 'web',
        ]];
    }

    /**
     * The transcribed query a voice response echoes back (visible search term).
     *
     * @param string $body
     * @return string
     */
    private function transcription(string $body): string
    {
        $decoded = json_decode($body, true);
        $query = is_array($decoded) ? ($decoded['query'] ?? '') : '';
        return is_string($query) ? $query : '';
    }

    /**
     * A result carrying no products, in the shape every caller expects.
     *
     * @param string $code
     * @param int $status
     * @return array
     */
    private function empty(string $code, int $status): array
    {
        return ['code' => $code, 'ids' => [], 'variants' => [], 'query' => '', 'status' => $status];
    }
}
