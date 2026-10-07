<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Config\Settings;

/**
 * Quissly's store billing API: the plans a store can buy, and buying one.
 *
 * Contract: chat-backend-middleware `app/applications/billing/store_router.py`
 * (STORE_BILLING_API.md), on the console host. `GET /plans` is public; every
 * other route takes the store's API key in `X-Store-Api-Key` - the key Connect
 * stored, sent from this server only and never to the browser.
 *
 * Paid plans are sold only while Quissly's billing is open (`billing_open`,
 * otherwise a 403); the Free plan and reading the store's own plans never wait.
 */
class StoreBilling
{
    private const PATH = '/api/v1/billing/store';

    private const TIMEOUT_SECONDS = 15;

    /** The subscription states that mean the store has a live plan. */
    public const LIVE_STATUSES = ['trial', 'active', 'past_due'];

    /**
     * @param Settings $settings
     * @param CurlFactory $curlFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Every plan a store can buy, or null when Quissly could not be reached.
     *
     * @param int|null $websiteId
     * @return array|null {billing_open, currency, trial_days, plans[]}
     */
    public function plans(?int $websiteId = null): ?array
    {
        $result = $this->request('GET', '/plans', $websiteId, null, false);
        return $result['ok'] && is_array($result['data']['plans'] ?? null) ? $result['data'] : null;
    }

    /**
     * The store's plans, newest first, or null when they could not be read.
     *
     * @param int|null $websiteId
     * @return array|null {project_id, subscriptions[], trial_eligible}
     */
    public function subscriptions(?int $websiteId = null): ?array
    {
        $result = $this->request('GET', '/subscriptions', $websiteId);
        return $result['ok'] && is_array($result['data']['subscriptions'] ?? null) ? $result['data'] : null;
    }

    /**
     * The live plan of each product the store has, keyed by family.
     *
     * @param array $subscriptions What subscriptions() answered
     * @return array<string, array{subscription: array, plan: array}>
     */
    public function livePlans(array $subscriptions): array
    {
        $live = [];
        foreach ($subscriptions['subscriptions'] ?? [] as $row) {
            $status = (string)($row['subscription']['status'] ?? '');
            $family = (string)($row['plan']['family'] ?? '');
            // Newest first, so the first live row of a family is its plan.
            if ($family !== '' && !isset($live[$family]) && in_array($status, self::LIVE_STATUSES, true)) {
                $live[$family] = $row;
            }
        }
        return $live;
    }

    /**
     * Start a plan: a payment link to open, or the free plan switched on now.
     *
     * @param int|null $websiteId
     * @param string $planId
     * @param string $billingCycle monthly|annual
     * @return array{ok: bool, kind: string, pay_url: string, message: string}
     */
    public function checkout(?int $websiteId, string $planId, string $billingCycle): array
    {
        $result = $this->request('POST', '/checkout', $websiteId, [
            'plan_id' => $planId,
            'billing_cycle' => $billingCycle === 'annual' ? 'annual' : 'monthly',
        ]);
        if (!$result['ok']) {
            return ['ok' => false, 'kind' => '', 'pay_url' => '', 'message' => $result['message']];
        }

        $kind = (string)($result['data']['kind'] ?? '');
        $payUrl = (string)($result['data']['pay_url'] ?? '');
        // The link opens in the merchant's browser, so it must be a real https
        // page; anything else is a contract change worth refusing loudly.
        if ($kind === 'pay' && !str_starts_with($payUrl, 'https://')) {
            $this->logger->error('[quissly] store billing checkout answered without an https pay_url');
            return ['ok' => false, 'kind' => '', 'pay_url' => '', 'message' => $this->message(0, '')];
        }
        if ($kind !== 'pay' && $kind !== 'free_activated') {
            $this->logger->error(sprintf('[quissly] store billing checkout answered kind=%s', $kind));
            return ['ok' => false, 'kind' => '', 'pay_url' => '', 'message' => $this->message(0, '')];
        }

        return ['ok' => true, 'kind' => $kind, 'pay_url' => $payUrl, 'message' => ''];
    }

    /**
     * Used and left this month, per service; null when it could not be read.
     *
     * @param int|null $websiteId
     * @param string $subscriptionId
     * @return array|null {subscription_id, services[]}
     */
    public function usage(?int $websiteId, string $subscriptionId): ?array
    {
        $result = $this->request('GET', '/subscriptions/' . rawurlencode($subscriptionId) . '/usage', $websiteId);
        return $result['ok'] && is_array($result['data']['services'] ?? null) ? $result['data'] : null;
    }

    /**
     * The store's payments, newest first; null when they could not be read.
     *
     * @param int|null $websiteId
     * @param int $limit
     * @return array|null {invoices[], total, limit, offset}
     */
    public function invoices(?int $websiteId, int $limit = 12): ?array
    {
        $result = $this->request('GET', '/invoices?limit=' . max(1, min(100, $limit)) . '&offset=0', $websiteId);
        return $result['ok'] && is_array($result['data']['invoices'] ?? null) ? $result['data'] : null;
    }

    /**
     * One plan-management call on a subscription.
     *
     * Ops: invoice_pdf (GET, id is the invoice), change_preview / change {plan_id},
     * topup_preview / topup {idempotency_key}, cancel, resume, abort, payment_method.
     *
     * @param int|null $websiteId
     * @param string $op
     * @param string $id Subscription id (invoice id for invoice_pdf)
     * @param array $body
     * @return array{ok: bool, data: array, message: string}
     */
    public function manage(?int $websiteId, string $op, string $id, array $body = []): array
    {
        $routes = [
            'invoice_pdf' => ['GET', '/invoices/%s/pdf'],
            'change_preview' => ['POST', '/subscriptions/%s/change-plan/preview'],
            'change' => ['POST', '/subscriptions/%s/change-plan'],
            'topup_preview' => ['POST', '/subscriptions/%s/topup/preview'],
            'topup' => ['POST', '/subscriptions/%s/topup'],
            'cancel' => ['POST', '/subscriptions/%s/cancel'],
            'resume' => ['POST', '/subscriptions/%s/resume'],
            'abort' => ['POST', '/subscriptions/%s/abort-scheduled-change'],
            'payment_method' => ['POST', '/subscriptions/%s/payment-method'],
        ];
        if (!isset($routes[$op]) || !preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
            return ['ok' => false, 'data' => [], 'message' => $this->message(0, '')];
        }
        [$method, $path] = $routes[$op];
        $result = $this->request($method, sprintf($path, $id), $websiteId, $method === 'POST' ? $body : null);
        $url = (string)($result['data']['url'] ?? $result['data']['pay_url'] ?? '');
        // A link opened in the merchant's browser must be a real https page.
        $opensLink = in_array($op, ['invoice_pdf', 'payment_method'], true);
        if ($result['ok'] && $opensLink && !str_starts_with($url, 'https://')) {
            return ['ok' => false, 'data' => [], 'message' => $this->message(0, '')];
        }
        return ['ok' => $result['ok'], 'data' => $result['data'], 'message' => $result['message']];
    }

    /**
     * One call; never throws.
     *
     * @param string $method
     * @param string $path
     * @param int|null $websiteId
     * @param array|null $body
     * @param bool $authenticated
     * @return array{ok: bool, status: int, data: array, message: string}
     */
    private function request(
        string $method,
        string $path,
        ?int $websiteId,
        ?array $body = null,
        bool $authenticated = true
    ): array {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        if ($authenticated) {
            $key = $this->settings->apiToken($websiteId);
            if ($key === null) {
                return ['ok' => false, 'status' => 401, 'data' => [], 'message' => $this->message(401, '')];
            }
            $curl->addHeader('X-Store-Api-Key', $key);
        }

        $url = $this->settings->consoleUrl($websiteId) . self::PATH . $path;
        try {
            if ($method === 'POST') {
                $curl->addHeader('Content-Type', 'application/json');
                $curl->post($url, json_encode($body ?? [], JSON_UNESCAPED_SLASHES) ?: '{}');
            } else {
                $curl->get($url);
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[quissly] store billing %s %s transport failure', $method, $path));
            return ['ok' => false, 'status' => 0, 'data' => [], 'message' => $this->message(0, '')];
        }

        $status = (int)$curl->getStatus();
        $decoded = json_decode((string)$curl->getBody(), true);
        $data = is_array($decoded) ? $decoded : [];
        if ($status >= 200 && $status < 300 && is_array($decoded)) {
            return ['ok' => true, 'status' => $status, 'data' => $data, 'message' => ''];
        }

        $detail = is_string($data['detail'] ?? null) ? (string)$data['detail'] : '';
        // Status and route only: the detail can carry the merchant's own words.
        $this->logger->info(sprintf('[quissly] store billing %s %s http=%d', $method, $path, $status));
        return ['ok' => false, 'status' => $status, 'data' => $data, 'message' => $this->message($status, $detail)];
    }

    /**
     * What the merchant is told, per the contract's error table.
     *
     * @param int $status
     * @param string $detail Quissly's own wording, shown where it is meant for the merchant
     * @return string
     */
    public function message(int $status, string $detail): string
    {
        switch ($status) {
            case 400:
            case 402:
            case 409:
                if ($detail !== '') {
                    return $detail;
                }
                break;
            case 401:
                return (string)__('Quissly did not recognise this store. Check the connection in Configuration.');
            case 403:
                return (string)__('Paid plans are not on sale yet. You can start on the free plan now.');
            case 502:
                return (string)__('The payment provider did not answer. Try again in a minute.');
            case 503:
                return (string)__('Billing is not available right now. Try again later.');
        }
        return (string)__('Could not reach Quissly. Try again in a minute.');
    }
}
