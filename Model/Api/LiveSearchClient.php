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
 * Live qsearch client for the interception path.
 *
 * Contract, live-confirmed against the deployed API:
 * modelled fields only (extra="forbid"); strict 2 s budget via HttpClient; ids
 * validated per the ID-boundary rule. A storefront search passes who is searching
 * (Model/Search/Shopper: user_id, device, os - its results page is then not cached,
 * Plugin/Frontend/SearchPageCache); anything else (the showcase cron) sends user_id null.
 */
class LiveSearchClient
{
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
     * One page of ranked product ids for a query.
     *
     * @param string $query Trimmed shopper query (guard-validated)
     * @param int $pageNumber 1-based page number
     * @param int $pageSize
     * @param int|null $websiteId
     * @param array $shopper who is searching: user_id / device / os (Shopper::forSearch())
     * @return SearchOutcome
     */
    public function search(
        string $query,
        int $pageNumber,
        int $pageSize,
        ?int $websiteId,
        array $shopper = []
    ): SearchOutcome {
        $token = $this->settings->apiToken($websiteId);
        $privateKey = $this->settings->privateKeyPem($websiteId);
        if ($token === null || $privateKey === null) {
            return new SearchOutcome(ResponseClassifier::NOT_CONFIGURED);
        }

        try {
            $response = $this->httpClient->postV2(
                HttpClient::PATH_QSEARCH,
                [
                    'query' => $query,
                    'user_id' => $shopper['user_id'] ?? null,
                    'page_number' => max(1, $pageNumber),
                    'page_size' => max(1, $pageSize),
                    'sort_by' => 0,
                    'sort_type' => 2,
                    'channel' => 'web',
                ] + array_intersect_key($shopper, ['device' => true, 'os' => true]),
                $token,
                $privateKey,
                $this->settings->environment($websiteId),
                false,
                null,
                $this->settings->apiBaseUrl($websiteId)
            );
        } catch (SignerException $e) {
            $this->logger->error('[quissly] signer failure on search path: ' . $e->getMessage());
            return new SearchOutcome(ResponseClassifier::NOT_CONFIGURED);
        }

        $code = $this->classifier->classify(
            $response['status'],
            $response['content_type'],
            $response['body'],
            $response['server_time'],
            time()
        );
        if ($code !== ResponseClassifier::OK) {
            $this->logger->info(sprintf('[quissly] qsearch http=%d code=%s', $response['status'], $code));
            return new SearchOutcome($code);
        }

        $parsed = $this->parser->parse($response['body']);
        if ($parsed['malformed']) {
            $this->logger->error('[quissly] qsearch 200 with unparseable body');
            return new SearchOutcome(ResponseClassifier::UNEXPECTED);
        }
        if ($parsed['dropped'] > 0) {
            $this->logger->warning(sprintf('[quissly] dropped %d malformed ids', $parsed['dropped']));
        }
        // Log the success too: without it a silent log is ambiguous between
        // "Quissly answered" and "the guard never ran", which is the one
        // question an operator most needs the log to settle.
        $this->logger->info(sprintf(
            '[quissly] qsearch http=200 code=ok ids=%d total=%d',
            count($parsed['ids']),
            (int)$parsed['total']
        ));
        return new SearchOutcome(
            ResponseClassifier::OK,
            $parsed['ids'],
            $parsed['total'],
            $parsed['variants'] ?? []
        );
    }
}
