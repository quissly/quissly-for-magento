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
 * Finish Setup: what the plan includes switches on, Setup is done, the Dashboard takes over.
 *
 * With later=1 it is the Shopify app's "Do this later": Setup is done but nothing is switched
 * on, for a merchant who is not ready yet (or whose first sync needs another look).
 */
class Finish extends Action implements HttpPostActionInterface
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
     * Go live, once the first sync has finished.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        if ($this->getRequest()->getParam('later') === '1') {
            if (!$this->onboarding->saveForLater()) {
                return $result->setData([
                    'ok' => false,
                    'message' => (string)__('Quissly is still building your services. Try again in a minute.'),
                ]);
            }
            $this->messageManager->addSuccessMessage(__(
                'Setup saved. Quissly stays off until you switch it on in Configuration, '
                . 'once your catalog has synced.'
            ));
            return $result->setData(['ok' => true, 'redirect' => $this->getUrl('quissly/dashboard/index')]);
        }
        if (!$this->onboarding->goLive()) {
            return $result->setData([
                'ok' => false,
                'message' => (string)__(
                    'Your catalog is still on its way to Quissly. Finish Setup unlocks when it is ready.'
                ),
            ]);
        }
        $this->messageManager->addSuccessMessage(
            in_array('qsearch', $this->onboarding->services(), true) || $this->onboarding->services() === []
                ? __('Quissly search is live on your store.')
                : __('Your AI shopping assistant is live on your store.')
        );
        return $result->setData(['ok' => true, 'redirect' => $this->getUrl('quissly/dashboard/index')]);
    }
}
