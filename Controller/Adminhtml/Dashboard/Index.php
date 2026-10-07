<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Quissly\Search\Model\Connect\Onboarding;

/**
 * The Quissly dashboard the README has always promised.
 *
 * Everything shown here was already being written - the first-sync gate, the
 * sync progress flag, the health record, the per-tenant capability gates - and
 * none of it was displayed anywhere. A merchant could not tell whether
 * interception was live, whether the catalog had synced, or why a feature they
 * enabled was doing nothing.
 */
class Index extends Action implements HttpGetActionInterface
{
    /**
     * ACL resource, declared in etc/acl.xml since the beginning.
     */
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
     * Render the dashboard page.
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
        $page->setActiveMenu('Quissly_Search::dashboard');
        $page->getConfig()->getTitle()->prepend(__('Quissly Dashboard'));
        return $page;
    }
}
