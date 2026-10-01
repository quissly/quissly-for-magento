<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Plugin\Frontend;

use Magento\Framework\Api\Search\DocumentFactory;
use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Api\LiveSearchClient;
use Quissly\Search\Model\Api\ResponseClassifier;
use Quissly\Search\Model\Api\SearchOutcome;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Health\HealthRecorder;
use Quissly\Search\Model\Search\CriteriaInspector;
use Quissly\Search\Model\Search\SearchSignal;
use Quissly\Search\Model\Search\Shopper;
use Quissly\Search\Model\Search\TokenStore;
use Quissly\Search\Model\Search\VariantHints;
use Quissly\Search\Model\Sync\FirstSyncGate;
use Quissly\Search\Observer\SearchDiagnosticHeader;

/**
 * THE interception seam (approved 2026-08-15):
 * around-plugin on SearchInterface::search, frontend area only.
 *
 * When every guard condition holds, the engine call is replaced with one
 * Quissly qsearch: Magento's own SearchResultApplier then renders our ids in
 * our order (ORDER BY FIELD) and derives the pager from our total. On ANY
 * guard miss or failure: $proceed() - native OpenSearch, untouched criteria.
 */
class SearchPlugin
{
    /**
     * @param Settings $settings
     * @param CriteriaInspector $inspector
     * @param FirstSyncGate $gate
     * @param LiveSearchClient $client
     * @param HealthRecorder $health
     * @param DocumentFactory $documentFactory
     * @param SearchResultFactory $resultFactory
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
     */
    /**
     * Per-request memo of a consumed hand-off token's ids: the
     * token is single-use (read+delete), but SearchInterface::search can run
     * more than once per page, so the first consume is remembered here.
     *
     * @var int[]|null
     */
    private ?array $handoff = null;

    /**
     * @param Settings $settings
     * @param CriteriaInspector $inspector
     * @param FirstSyncGate $gate
     * @param LiveSearchClient $client
     * @param HealthRecorder $health
     * @param DocumentFactory $documentFactory
     * @param SearchResultFactory $resultFactory
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param RequestInterface $request
     * @param TokenStore $tokenStore
     * @param VariantHints $variantHints
     * @param SearchSignal $signal
     * @param Shopper $shopper
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly CriteriaInspector $inspector,
        private readonly FirstSyncGate $gate,
        private readonly LiveSearchClient $client,
        private readonly HealthRecorder $health,
        private readonly DocumentFactory $documentFactory,
        private readonly SearchResultFactory $resultFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly RequestInterface $request,
        private readonly TokenStore $tokenStore,
        private readonly VariantHints $variantHints,
        private readonly SearchSignal $signal,
        private readonly Shopper $shopper
    ) {
    }

    /**
     * Intercept storefront quick search per the approved design; else proceed.
     *
     * @param SearchInterface $subject
     * @param callable $proceed
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSearch(
        SearchInterface $subject,
        callable $proceed,
        SearchCriteriaInterface $searchCriteria
    ): SearchResultInterface {
        // Guard conditions 1 - 4 + 6 (criteria shape, term sanity).
        $reason = $this->inspector->rejectionReason($searchCriteria);
        if ($reason !== null) {
            $this->skipped($reason);
            return $proceed($searchCriteria);
        }
        // Voice/image hand-off: a single-use token on the results URL carries
        // pre-computed ids - render them directly, no live query, no gate (it
        // is our own token). One snapshot page, our order.
        $handoff = $this->handoffResult($searchCriteria);
        if ($handoff !== null) {
            return $handoff;
        }
        // Guard condition 5: module gates for the CURRENT website context
        // (Settings resolves the store context's website when passed null).
        $setting = match (true) {
            !$this->settings->isSearchEnabled(null) => 'search-off',
            !$this->settings->isConfigured(null) => 'not-configured',
            !$this->gate->isOpen($this->currentWebsiteId()) => 'gate-closed',
            default => null,
        };
        if ($setting !== null) {
            $this->skipped($setting);
            return $proceed($searchCriteria);
        }

        $term = trim((string)$this->inspector->searchTerm($searchCriteria));
        // Criteria page is 0-based (spike-verified); Quissly is 1-based.
        $pageNumber = (int)$searchCriteria->getCurrentPage() + 1;
        $pageSize = (int)$searchCriteria->getPageSize() ?: 12;

        // Who is searching (user_id / device / os): this page is not cached while Quissly
        // answers (SearchPageCache), so it is this shopper's.
        $outcome = $this->client->search($term, $pageNumber, $pageSize, null, $this->shopper->forSearch());

        // Search-channel health only: a refused shopper search says nothing about
        // whether catalog ingestion is working, and the admin's catalog banner
        // reads the catalog channel.
        if ($outcome->isAuthClass()) {
            $this->health->recordFailure(
                $this->currentWebsiteId(),
                $outcome->code,
                HealthRecorder::CHANNEL_SEARCH
            );
        } elseif ($outcome->isOk()) {
            $this->health->recordSuccess($this->currentWebsiteId(), HealthRecorder::CHANNEL_SEARCH);
        }

        if (!$outcome->isOk()) {
            return $this->fallback($proceed, $searchCriteria, $outcome, $pageNumber);
        }

        // 200 with zero documents is a REAL answer: render the theme's empty state.
        $this->signal->recordHit(count($outcome->ids), (int)$outcome->total);
        // Which child matched, for the link plugin to read while the theme
        // renders. Magento is handed the parent - the child is not visible
        // individually - so without this the shopper who searched "red tee"
        // lands on whichever colour the parent defaults to.
        $this->variantHints->remember($outcome->variants);
        return $this->buildResult($searchCriteria, $outcome);
    }

    /**
     * Failure path per the fallback matrix.
     *
     * Page 1 → silent native; page 2+ → graceful empty (never a mid-pagination
     * universe swap).
     *
     * @param callable $proceed
     * @param SearchCriteriaInterface $criteria
     * @param SearchOutcome $outcome
     * @param int $pageNumber
     * @return SearchResultInterface
     */
    private function fallback(
        callable $proceed,
        SearchCriteriaInterface $criteria,
        SearchOutcome $outcome,
        int $pageNumber
    ): SearchResultInterface {
        $this->logger->info(sprintf(
            '[quissly] interception fallback code=%s page=%d',
            $outcome->code,
            $pageNumber
        ));
        $this->signal->recordFallback($outcome->code);
        if ($pageNumber <= 1) {
            return $proceed($criteria);
        }
        return $this->buildResult($criteria, new SearchOutcome(ResponseClassifier::OK, [], 0));
    }

    /**
     * The module steps aside on this search: say why.
     *
     * Stepping aside is often correct (a shopper's own sort or filter), but it
     * used to be completely silent - no header, no badge, no log - which is
     * what made an MSI sort order that skipped every search on a live store
     * take days to find. The reason goes to the diagnostic header and the
     * ?quissly_debug=1 badge; the log line only when the flag asks for it, so a
     * busy store's log does not fill with price-sorted searches. Requests that
     * are no search at all (category pages) stay silent.
     *
     * @param string $reason
     * @return void
     */
    private function skipped(string $reason): void
    {
        if ($reason === CriteriaInspector::NOT_A_SEARCH) {
            return;
        }
        $this->signal->recordSkip($reason);
        if ($this->request->getParam(SearchDiagnosticHeader::DEBUG_PARAM) !== null) {
            $this->logger->info(sprintf('[quissly] search skipped reason=%s', $reason));
        }
    }

    /**
     * Render a voice/image hand-off token's ids, or null when no token is present.
     *
     * The token is consumed once per request (single-use read+delete) and
     * memoized. An expired/replayed token yields [] → the theme's empty state,
     * never a fall-through to a live query (token results are a snapshot).
     *
     * @param SearchCriteriaInterface $criteria
     * @return SearchResultInterface|null
     */
    private function handoffResult(SearchCriteriaInterface $criteria): ?SearchResultInterface
    {
        $token = $this->handoffToken();
        if ($token === null) {
            return null;
        }
        if ($this->handoff === null) {
            $this->handoff = $this->tokenStore->consume($token);
            $this->logger->info(sprintf(
                '[quissly] handoff token consumed ids=%d variants=%d',
                count($this->handoff['ids']),
                count($this->handoff['variants'])
            ));
        }
        // The same hint the typed path records. Without it a voice or image
        // search returned the right products wearing the parent's face, while
        // the identical query typed showed the matched variant.
        $this->variantHints->remember($this->handoff['variants']);
        return $this->buildResult(
            $criteria,
            new SearchOutcome(
                ResponseClassifier::OK,
                $this->handoff['ids'],
                count($this->handoff['ids']),
                $this->handoff['variants']
            )
        );
    }

    /**
     * The hand-off token on the current results URL, if any.
     *
     * @return string|null
     */
    private function handoffToken(): ?string
    {
        foreach (['quissly_img', 'quissly_voice'] as $param) {
            $value = $this->request->getParam($param);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Build the SearchResult per the design.
     *
     * Page-slice documents (id only), full total, aggregations null (never
     * partial), criteria mirrored.
     *
     * @param SearchCriteriaInterface $criteria
     * @param SearchOutcome $outcome
     * @return SearchResultInterface
     */
    private function buildResult(
        SearchCriteriaInterface $criteria,
        SearchOutcome $outcome
    ): SearchResultInterface {
        $documents = [];
        foreach ($outcome->ids as $id) {
            $document = $this->documentFactory->create();
            $document->setId($id);
            $documents[] = $document;
        }
        $result = $this->resultFactory->create();
        $result->setItems($documents);
        $result->setTotalCount($outcome->total);
        $result->setAggregations(null);
        $result->setSearchCriteria($criteria);
        return $result;
    }

    /**
     * Current store context's website id.
     *
     * @return int
     */
    private function currentWebsiteId(): int
    {
        return (int)$this->storeManager->getStore()->getWebsiteId();
    }
}
