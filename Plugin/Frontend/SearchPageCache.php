<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Plugin\Frontend;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Layout;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Sync\FirstSyncGate;

/**
 * Search results are never served from the full-page cache while Quissly answers searches.
 *
 * Magento caches the results page of its most popular search terms (PopularSearchTerms, top
 * 100 by default): every later search for that word is served the saved page and never reaches
 * Quissly - it goes uncounted, and cannot say who searched (Model/Search/Shopper). A page saved
 * while Quissly was briefly unreachable also keeps serving the native fallback. So the search
 * results layout reports itself not cacheable: Magento then neither caches the page nor clears
 * the customer session while building it (DepersonalizePlugin), and every search reaches Quissly
 * with the shopper's details - as on the WooCommerce and CS-Cart plugins, whose
 * search pages are not cached. Only while Quissly is answering: otherwise Magento caches as usual.
 */
class SearchPageCache
{
    private const RESULTS_PAGE = 'catalogsearch_result_index';

    /** @var bool|null memo: isCacheable() is asked several times per page */
    private ?bool $answering = null;

    /**
     * @param RequestInterface $request
     * @param Settings $settings
     * @param FirstSyncGate $gate
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Settings $settings,
        private readonly FirstSyncGate $gate,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Not cacheable: the search results page while Quissly answers searches.
     *
     * @param Layout $subject
     * @param bool $result
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsCacheable(Layout $subject, $result)
    {
        if (!$result || !method_exists($this->request, 'getFullActionName')
            || $this->request->getFullActionName() !== self::RESULTS_PAGE
        ) {
            return $result;
        }
        return !$this->quisslyAnswers();
    }

    /**
     * Search on, configured, and the first sync done for this website (SearchPlugin's own gates).
     *
     * @return bool
     */
    private function quisslyAnswers(): bool
    {
        if ($this->answering === null) {
            try {
                $this->answering = $this->settings->isSearchEnabled(null)
                    && $this->settings->isConfigured(null)
                    && $this->gate->isOpen((int)$this->storeManager->getStore()->getWebsiteId());
            } catch (\Throwable $e) {
                $this->answering = false;
            }
        }
        return $this->answering;
    }
}
