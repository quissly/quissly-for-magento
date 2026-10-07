<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\Panel;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Quissly\Search\Model\Connect\Onboarding;

/**
 * The Quissly admin panel, embedded in the Magento admin.
 *
 * Spares the merchant a second login: the module already holds the store's API
 * key, and the console can exchange it for a session.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::dashboard';

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
     * Render the embedded panel.
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
        $page->setActiveMenu('Quissly_Search::panel');
        $page->getConfig()->getTitle()->prepend(__('Quissly Admin Panel'));
        return $page;
    }
}
