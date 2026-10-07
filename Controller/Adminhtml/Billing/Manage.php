<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\Billing;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Quissly\Search\Model\Api\StoreBilling;
use Quissly\Search\Model\Connect\Onboarding;

/**
 * Every Billing action, against Quissly's store billing API (STORE_BILLING_API.md).
 *
 * The page posts op= plus the subscription (or invoice) id; previews come back already in
 * words, so billing.js carries no prices or dates of its own. Quissly checks the id belongs to
 * this store (another store's answers 404). Completed changes flash a message for the reload.
 */
class Manage extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::billing';

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
     * Run one billing action.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $request = $this->getRequest();
        $websiteId = $this->onboarding->websiteId();
        $op = (string)$request->getParam('op');
        $id = (string)$request->getParam('id');

        switch ($op) {
            case 'checkout':
                $checkout = $this->billing->checkout(
                    $websiteId,
                    (string)$request->getParam('plan_id'),
                    (string)$request->getParam('billing_cycle')
                );
                if ($checkout['ok'] && $checkout['kind'] === 'free_activated') {
                    $this->messageManager->addSuccessMessage(__('The free plan is on.'));
                    return $this->answer(['ok' => true, 'done' => true]);
                }
                return $this->answer($checkout['ok']
                    ? ['ok' => true, 'pay_url' => $checkout['pay_url']]
                    : ['ok' => false, 'message' => $checkout['message']]);

            case 'check':
                $subscriptions = $this->billing->subscriptions($websiteId);
                $active = $subscriptions !== null
                    && isset($this->billing->livePlans($subscriptions)[(string)$request->getParam('family')]);
                if ($active) {
                    $this->messageManager->addSuccessMessage(__('Payment received - your plan is on.'));
                }
                return $this->answer(['ok' => true, 'active' => $active]);

            case 'change_preview':
                $result = $this->billing->manage($websiteId, $op, $id, [
                    'plan_id' => (string)$request->getParam('plan_id'),
                ]);
                return $this->answer($result['ok']
                    ? [
                        'ok' => true,
                        'summary' => $this->changeSummary($result['data']),
                        'confirm' => (string)__('Change plan'),
                    ]
                    : ['ok' => false, 'message' => $result['message']]);

            case 'change':
                $result = $this->billing->manage($websiteId, $op, $id, [
                    'plan_id' => (string)$request->getParam('plan_id'),
                ]);
                if ($result['ok']) {
                    $this->messageManager->addSuccessMessage($this->changeDone($result['data']));
                }
                return $this->done($result);

            case 'topup_preview':
                $result = $this->billing->manage($websiteId, $op, $id);
                $data = $result['data'];
                return $this->answer($result['ok']
                    ? ['ok' => true, 'confirm' => (string)__('Buy now'), 'summary' => (string)__(
                        '%1 extra requests for %2, charged to your saved card now. They carry over to next '
                        . 'month while the plan continues.',
                        number_format((int)($data['requests'] ?? 0)),
                        $this->amount($data['charge_now'] ?? 0, (string)($data['currency'] ?? 'USD'))
                    )]
                    : ['ok' => false, 'message' => $result['message']]);

            case 'topup':
                $result = $this->billing->manage($websiteId, $op, $id, [
                    'idempotency_key' => (string)$request->getParam('idempotency_key'),
                ]);
                if ($result['ok']) {
                    $this->messageManager->addSuccessMessage(($result['data']['kind'] ?? '') === 'processing'
                        ? __('Payment is being confirmed; the extra requests are added as soon as it lands.')
                        : __('%1 extra requests added.', number_format((int)($result['data']['requests'] ?? 0))));
                }
                return $this->done($result);

            case 'cancel':
            case 'resume':
            case 'abort':
                $result = $this->billing->manage($websiteId, $op, $id);
                if ($result['ok']) {
                    $this->messageManager->addSuccessMessage([
                        'cancel' => __('Your plan is cancelled and ends at the end of the paid period.'),
                        'resume' => __('Your plan continues.'),
                        'abort' => __('The scheduled change is undone.'),
                    ][$op]);
                }
                return $this->done($result);

            case 'payment_method':
            case 'invoice_pdf':
                $result = $this->billing->manage($websiteId, $op, $id);
                $url = (string)($result['data']['url'] ?? $result['data']['pay_url'] ?? '');
                return $this->answer($result['ok']
                    ? ['ok' => true, 'url' => $url]
                    : ['ok' => false, 'message' => $result['message']]);
        }

        return $this->answer(['ok' => false, 'message' => (string)__('Unknown request.')]);
    }

    /**
     * What a plan change does, before it is made.
     *
     * @param array $preview {kind, charge_now, currency, effective_at}
     * @return string
     */
    private function changeSummary(array $preview): string
    {
        $when = $this->day((string)($preview['effective_at'] ?? ''));
        switch ((string)($preview['kind'] ?? '')) {
            case 'upgrade':
                return (string)__(
                    '%1 is charged to your saved card now (the rest of this period), and the new plan starts at once.',
                    $this->amount($preview['charge_now'] ?? 0, (string)($preview['currency'] ?? 'USD'))
                );
            case 'downgrade':
                return (string)__('Nothing is charged or refunded. Your plan changes on %1.', $when);
            case 'trial_swap':
                return (string)__('Free during your trial. The plan changes now.');
        }
        return (string)__('Your plan changes.');
    }

    /**
     * What a plan change did.
     *
     * @param array $result {kind, subscription}
     * @return \Magento\Framework\Phrase
     */
    private function changeDone(array $result): \Magento\Framework\Phrase
    {
        switch ((string)($result['kind'] ?? '')) {
            case 'scheduled':
                return __(
                    'Your plan changes on %1.',
                    $this->day((string)($result['subscription']['current_period_end'] ?? ''))
                );
            case 'processing':
                return __('Payment is being confirmed; your new plan starts as soon as it lands.');
        }
        return __('Your plan has changed.');
    }

    /**
     * "$50.00".
     *
     * @param mixed $value
     * @param string $currency
     * @return string
     */
    private function amount($value, string $currency): string
    {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $number = number_format((float)$value, 2);
        return isset($symbols[$currency]) ? $symbols[$currency] . $number : $currency . ' ' . $number;
    }

    /**
     * "2 Nov 2026".
     *
     * @param string $iso
     * @return string
     */
    private function day(string $iso): string
    {
        try {
            return $iso === '' ? '' : (new \DateTimeImmutable($iso))->format('j M Y');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * The answer to an op that is finished once it succeeds.
     *
     * @param array $result What StoreBilling::manage() answered
     * @return Json
     */
    private function done(array $result): Json
    {
        return $this->answer($result['ok']
            ? ['ok' => true, 'done' => true]
            : ['ok' => false, 'message' => $result['message']]);
    }

    /**
     * The JSON answer.
     *
     * @param array $data
     * @return Json
     */
    private function answer(array $data): Json
    {
        return $this->jsonFactory->create()->setData($data);
    }
}
