<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\MostLoved;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Search\MostLoved;

/**
 * The Most loved picker's search (web/js/most-loved-picker.js): products whose name or SKU
 * contains `q` (under 2 characters: the whole catalog), page `page` of 20, for the website in
 * `website` (else the default store's), as {items: [{sku, name, image, note}], more} - note says
 * why a product cannot be shown, '' when it can.
 * Admin only, behind the Configuration permission; it reads the catalog and changes nothing.
 */
class Search extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::config';

    /**
     * @param Action\Context $context
     * @param MostLoved $mostLoved
     * @param StoreManagerInterface $storeManager
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        Action\Context $context,
        private readonly MostLoved $mostLoved,
        private readonly StoreManagerInterface $storeManager,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Answer one search.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $websiteId = (int)$this->getRequest()->getParam('website', 0);
        try {
            $website = $websiteId > 0
                ? $this->storeManager->getWebsite($websiteId)
                : $this->storeManager->getDefaultStoreView()->getWebsite();
            $storeId = (int)$website->getDefaultStore()->getId();
            $websiteId = (int)$website->getId();
        } catch (\Throwable $e) {
            return $result->setData(['items' => [], 'more' => false]);
        }
        $reasons = [
            'out_of_stock' => (string)__('out of stock'),
            'not_visible' => (string)__('not visible in the store'),
            'not_found' => (string)__('no product has this SKU'),
        ];
        $found = $this->mostLoved->search(
            (string)$this->getRequest()->getParam('q'),
            $websiteId,
            $storeId,
            max(1, (int)$this->getRequest()->getParam('page', 1))
        );
        $out = [];
        foreach ($found['items'] as $item) {
            $out[] = [
                'sku' => $item['sku'],
                'name' => $item['name'],
                'image' => $item['image'],
                'note' => $item['reason'] === '' ? '' : ($reasons[$item['reason']] ?? $item['reason']),
            ];
        }
        return $result->setData(['items' => $out, 'more' => $found['more']]);
    }
}
