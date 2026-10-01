<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Cart;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Cart\ChatIdMap;
use Quissly\Search\Model\Cart\ChatIds;

/**
 * GET /quissly/cart/resolve?ids=uuid,uuid - Quissly's product ids (the chat
 * widget's) => Magento product ids, for the ones this website has. Read-only
 * and public, like search; product ids are not secret.
 */
class Resolve implements HttpGetActionInterface
{
    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param StoreManagerInterface $storeManager
     * @param ChatIdMap $chatIdMap
     * @param ChatIds $chatIds
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ChatIdMap $chatIdMap,
        private readonly ChatIds $chatIds
    ) {
    }

    /**
     * Answer {quissly_id: product_id} for the known ids.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $ids = $this->chatIds->validIds(explode(',', (string)$this->request->getParam('ids', '')));
        $websiteId = (int)$this->storeManager->getWebsite()->getId();

        return $this->jsonFactory->create()
            ->setHeader('Cache-Control', 'private, max-age=300', true)
            ->setData((object)$this->chatIdMap->resolve($ids, $websiteId));
    }
}
