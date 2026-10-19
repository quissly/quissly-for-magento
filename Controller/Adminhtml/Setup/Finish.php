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
use Quissly\Search\Model\Api\ConnectionService;
use Quissly\Search\Model\Api\ResponseClassifier;
use Quissly\Search\Model\Api\SearchOutcome;
use Quissly\Search\Model\Connect\Onboarding;
use Quissly\Search\Model\Health\HealthRecorder;

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
     * @param ConnectionService $connection
     * @param HealthRecorder $health
     */
    public function __construct(
        Action\Context $context,
        private readonly Onboarding $onboarding,
        private readonly JsonFactory $jsonFactory,
        private readonly ConnectionService $connection,
        private readonly HealthRecorder $health
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
        $search = in_array('qsearch', $this->onboarding->services(), true) || $this->onboarding->services() === [];
        // Search is switched on: one search says whether Quissly answers it, so the Dashboard does
        // not say Live for a store whose searches Quissly refuses (no plan it knows of - a plan
        // chosen for manual invoice; found testing the CS-Cart add-on, 2026-10-19).
        if ($search) {
            $websiteId = $this->onboarding->websiteId();
            $probe = $this->connection->test($websiteId);
            if ((new SearchOutcome($probe['code']))->isAuthClass()) {
                $this->health->recordFailure(
                    $websiteId,
                    $probe['code'],
                    HealthRecorder::CHANNEL_SEARCH,
                    $probe['detail']
                );
                $this->messageManager->addWarningMessage(__(
                    'Setup is finished, but Quissly refused the first search, so the store keeps '
                    . 'Magento\'s search for now. %1',
                    $probe['message']
                ));
                return $result->setData(['ok' => true, 'redirect' => $this->getUrl('quissly/dashboard/index')]);
            }
            if ($probe['code'] === ResponseClassifier::OK) {
                $this->health->recordSuccess($websiteId, HealthRecorder::CHANNEL_SEARCH);
            }
        }
        $this->messageManager->addSuccessMessage(
            $search
                ? __('Quissly search is live on your store.')
                : __('Your AI shopping assistant is live on your store.')
        );
        return $result->setData(['ok' => true, 'redirect' => $this->getUrl('quissly/dashboard/index')]);
    }
}
