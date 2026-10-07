<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\Billing;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Quissly\Search\Model\Connect\Onboarding;

/**
 * Quissly Billing - the store's plans, usage and invoices (the menu's fourth entry).
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::billing';

    /**
     * @param Action\Context $context
     * @param PageFactory $pageFactory
     * @param Onboarding $onboarding
     */
    public function __construct(
        Action\Context $context,
        private readonly PageFactory $pageFactory,
        private readonly Onboarding $onboarding
    ) {
        parent::__construct($context);
    }

    /**
     * Render Billing, or Quissly Setup while that is unfinished.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        // Until Quissly Setup is done, every Quissly menu entry opens it.
        if (!$this->onboarding->isComplete()) {
            return $this->resultRedirectFactory->create()->setPath('quissly/setup/index');
        }
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Quissly_Search::billing');
        $page->getConfig()->getTitle()->prepend(__('Quissly Billing'));
        return $page;
    }
}
