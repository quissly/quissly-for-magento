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
use Quissly\Search\Model\Api\StoreBilling;
use Quissly\Search\Model\Connect\Onboarding;

/**
 * The plan step, against Quissly's store billing API.
 *
 * op=checkout  start the chosen plan: the free plan is on at once; a paid one
 *              answers a payment link the page opens in a new tab
 * op=check     whether a plan of that product is live yet (after paying)
 * op=keep      carry on with the plan the store already has
 * op=later     carry on without one - only while the plans cannot be loaded,
 *              so a Quissly outage never strands a merchant on this step
 */
class Plan extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::config';

    /**
     * @param Action\Context $context
     * @param Onboarding $onboarding
     * @param StoreBilling $billing
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        Action\Context $context,
        private readonly Onboarding $onboarding,
        private readonly StoreBilling $billing,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Run one plan operation.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        if (!$this->onboarding->isConnected()) {
            return $result->setData(['ok' => false, 'message' => (string)__('Connect the store first.')]);
        }
        $websiteId = $this->onboarding->websiteId();
        $request = $this->getRequest();

        switch ((string)$request->getParam('op')) {
            case 'checkout':
                $checkout = $this->billing->checkout(
                    $websiteId,
                    (string)$request->getParam('plan_id'),
                    (string)$request->getParam('billing_cycle')
                );
                if (!$checkout['ok']) {
                    return $result->setData(['ok' => false, 'message' => $checkout['message']]);
                }
                if ($checkout['kind'] === 'free_activated') {
                    $this->onboarding->planChosen();
                    return $result->setData(['ok' => true, 'kind' => 'active']);
                }
                return $result->setData(['ok' => true, 'kind' => 'pay', 'pay_url' => $checkout['pay_url']]);

            case 'check':
            case 'keep':
                $subscriptions = $this->billing->subscriptions($websiteId);
                if ($subscriptions === null) {
                    return $result->setData(['ok' => false, 'message' => $this->billing->message(0, '')]);
                }
                $live = $this->billing->livePlans($subscriptions);
                $family = (string)$request->getParam('family');
                $active = $family !== '' ? isset($live[$family]) : $live !== [];
                if ($active) {
                    $this->onboarding->planChosen();
                }
                return $result->setData([
                    'ok' => true,
                    'active' => $active,
                    'message' => $active ? '' : (string)__('Payment not received yet.'),
                ]);

            case 'later':
                if ($this->billing->plans($websiteId) !== null) {
                    return $result->setData(['ok' => false, 'message' => (string)__('Choose a plan to continue.')]);
                }
                $this->onboarding->planChosen();
                return $result->setData(['ok' => true]);
        }

        return $result->setData(['ok' => false, 'message' => (string)__('Unknown request.')]);
    }
}
