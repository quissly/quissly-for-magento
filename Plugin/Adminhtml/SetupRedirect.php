<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Plugin\Adminhtml;

use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Config\Controller\Adminhtml\System\Config\Edit;
use Magento\Framework\Controller\ResultInterface;
use Quissly\Search\Model\Connect\Onboarding;

/**
 * Quissly's Configuration leads to Quissly Setup until Setup is done.
 *
 * The menu's three entries stay where they are; until onboarding is finished
 * each of them opens Setup instead (the Dashboard and the panel do this in
 * their own controllers; Configuration is Magento's, hence this plugin).
 */
class SetupRedirect
{
    /**
     * @param Onboarding $onboarding
     * @param RedirectFactory $redirectFactory
     */
    public function __construct(
        private readonly Onboarding $onboarding,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    /**
     * Send the Quissly section to Setup while onboarding is unfinished.
     *
     * @param Edit $subject
     * @param callable $proceed
     * @return ResultInterface|mixed
     */
    public function aroundExecute(Edit $subject, callable $proceed)
    {
        if ($subject->getRequest()->getParam('section') === 'quissly' && !$this->onboarding->isComplete()) {
            return $this->redirectFactory->create()->setPath('quissly/setup/index');
        }
        return $proceed();
    }
}
