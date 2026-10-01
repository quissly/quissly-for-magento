<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Health\MediaCapability;

/**
 * Fetches type-ahead suggestions from /v2beta/quick.
 *
 * Separate from LiveSearchClient because the failure policy is opposite. A
 * failed SEARCH must fall back to Magento's engine so the shopper still gets
 * results; a failed SUGGESTION must simply not appear. Nobody is owed a
 * dropdown, and a broken one is worse than none.
 *
 * The per-tenant gate is the expected state, not an error: until Quissly runs
 * the index job the endpoint answers 404 "Quick suggestions are not enabled
 * for this service". That is recorded as a capability so the
 * admin can say WHY the feature shows nothing, rather than leaving a merchant
 * with a switch that appears to do nothing.
 */
class QuickClient
{
    public const KIND = 'quick';

    /** A shopper is typing. Anything slower than this is not type-ahead. */
    private const TIMEOUT_SECONDS = 2;

    /**
     * @param Settings $settings
     * @param HttpClient $httpClient
     * @param QuickResponseParser $parser
     * @param MediaCapability $capability
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClient $httpClient,
        private readonly QuickResponseParser $parser,
        private readonly MediaCapability $capability,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Ordered product ids for a partial query. Empty on every failure.
     *
     * Ids only: Quick returns no renderable metadata, and the dropdown's
     * names, prices and images are loaded from Magento by SuggestionHydrator.
     *
     * @param string $query
     * @param int|null $websiteId
     * @param string|null $userId who is searching (Model/Search/Shopper::userId())
     * @return array<int, int>
     */
    public function suggest(string $query, ?int $websiteId, ?string $userId = null): array
    {
        $token = $this->settings->apiToken($websiteId);
        $privateKey = $this->settings->privateKeyPem($websiteId);
        if ($token === null || $privateKey === null) {
            return [];
        }

        try {
            $response = $this->httpClient->postV2(
                HttpClient::PATH_QUICK,
                [
                    'query' => $query,
                    'user_id' => $userId,
                    'channel' => 'web',
                    // Without this the response carries ids and scores only -
                // no title, no image, nothing renderable.
                    'include_metadata' => true,
                ],
                $token,
                $privateKey,
                $this->settings->environment($websiteId),
                false,
                self::TIMEOUT_SECONDS,
                $this->settings->apiBaseUrl($websiteId)
            );
        } catch (SignerException $e) {
            $this->logger->error('[quissly] signer failure on quick path: ' . $e->getMessage());
            return [];
        }

        $status = (int)$response['status'];

        if ($status === 404) {
            // The per-tenant gate, not a missing route. Recorded so the admin
            // can explain the empty dropdown instead of guessing.
            $this->capability->markUnavailable($websiteId, self::KIND, 'not_enabled');
            $this->logger->info('[quissly] quick 404: not enabled for this service');
            return [];
        }

        if ($status !== 200) {
            $this->capability->markFailure($websiteId, self::KIND, 'http_' . $status);
            $this->logger->info(sprintf('[quissly] quick http=%d', $status));
            return [];
        }

        $this->capability->markAvailable($websiteId, self::KIND);

        return $this->parser->parse((string)$response['body']);
    }
}
