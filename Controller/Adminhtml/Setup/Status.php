<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\Setup;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Quissly\Search\Model\Connect\Onboarding;

/**
 * The Go live step's progress list, polled while the page is open.
 *
 * A POST because it moves Setup along: the first sync starts from here as soon
 * as the new service can take it (the cron does the same when the page is shut).
 */
class Status extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::config';

    /**
     * @param Action\Context $context
     * @param Onboarding $onboarding
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        Action\Context $context,
        private readonly Onboarding $onboarding,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Advance Setup and answer where it stands.
     *
     * @return Json
     */
    public function execute(): Json
    {
        if ($this->getRequest()->getParam('retry') === '1') {
            $this->onboarding->retrySync();
        }
        $this->onboarding->advance();
        return $this->jsonFactory->create()->setData(['ok' => true] + $this->onboarding->status());
    }
}
