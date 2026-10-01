<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\Connection;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Quissly\Search\Model\Api\ConnectionService;

/**
 * Admin Test Connection: one real signed qsearch for the selected scope,
 * classified per the fallback matrix.
 */
class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::config';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param ConnectionService $connectionService
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ConnectionService $connectionService
    ) {
        parent::__construct($context);
    }

    /**
     * Run the connection probe for the requested scope.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $raw = $this->getRequest()->getParam('website');
        $websiteId = ($raw === null || $raw === '' || (int)$raw === 0) ? 0 : (int)$raw;

        $outcome = $this->connectionService->test($websiteId);
        return $this->jsonFactory->create()->setData([
            'ok' => $outcome['code'] === \Quissly\Search\Model\Api\ResponseClassifier::OK,
            'code' => $outcome['code'],
            'http_status' => $outcome['http_status'],
            'message' => $outcome['message'],
        ]);
    }
}
