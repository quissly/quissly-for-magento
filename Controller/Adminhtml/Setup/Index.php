<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\Setup;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Quissly\Search\Model\Connect\Onboarding;

/**
 * Quissly Setup - where the Quissly menu leads until onboarding is done.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::config';

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
     * Render Setup, or the Dashboard once it is behind the store.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        if ($this->onboarding->isComplete()) {
            return $this->resultRedirectFactory->create()->setPath('quissly/dashboard/index');
        }
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Quissly_Search::quissly');
        $page->getConfig()->getTitle()->prepend(__('Quissly Setup'));
        return $page;
    }
}
