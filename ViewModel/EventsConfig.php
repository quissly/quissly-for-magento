<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Events\Recorder;

/**
 * What a product page or a search results page tells `quissly-events.js` to send.
 *
 * Page-constant only - the product id is the page's own, the query is in its URL - so the
 * block stays cacheable: the page cache keys a search page by its URL, query included.
 * Who the shopper is is decided when the event arrives (Controller/Events/Collect), never
 * rendered into a page another shopper may be served.
 */
class EventsConfig implements ArgumentInterface
{
    /**
     * @param Recorder $recorder
     * @param RequestInterface $request
     * @param UrlInterface $url
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly RequestInterface $request,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The event for this page, or null when there is nothing to send.
     *
     * @param string $page product | search (the layout handle's argument)
     * @return array|null {type, product_id} | {type, query, modality}
     */
    public function event(string $page): ?array
    {
        try {
            if (!$this->recorder->enabled((int)$this->storeManager->getStore()->getWebsiteId())) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        if ($page === 'product') {
            $id = (int)$this->request->getParam('id');
            return $id > 0 ? ['type' => 'view_PDP', 'product_id' => (string)$id] : null;
        }
        if ($page === 'search') {
            // Page 2, 3... of the same search is not another search.
            if ((int)$this->request->getParam('p', 1) > 1) {
                return null;
            }
            // A voice or image search's results page (Controller/Voice|Image/Search hand-off).
            if ((string)$this->request->getParam('quissly_voice', '') !== '') {
                return ['type' => 'search', 'query' => '', 'modality' => 'voice'];
            }
            if ((string)$this->request->getParam('quissly_img', '') !== '') {
                return ['type' => 'search', 'query' => '', 'modality' => 'image'];
            }
            $query = trim((string)$this->request->getParam('q', ''));
            return $query !== '' ? ['type' => 'search', 'query' => $query, 'modality' => 'text'] : null;
        }
        return null;
    }

    /**
     * Where the browser sends it.
     *
     * @return string
     */
    public function endpoint(): string
    {
        return $this->url->getUrl('quissly/events/collect');
    }
}
