<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Block\Adminhtml;

/**
 * Quissly Billing: the store's plans, this month's usage and its invoices.
 *
 * Everything Quissly's store billing API offers a store after Setup (STORE_BILLING_API.md),
 * laid out as the Shopify app's Settings billing column: a current-plan card per product
 * (search, chat) with its status, banners and the plan picker, then Usage and Extra
 * requests; invoices and the card on file on the right. A product with no plan offers the
 * plans to start one. The plan figures come from Quissly Setup (this block extends it).
 */
class Billing extends Setup
{
    /** The products a store can buy, in the order they are shown. */
    public const FAMILIES = ['qsearch', 'qchat'];

    /** @var array|null|false Built once per request; false = not built yet */
    private $view = false;

    /**
     * The page's script configuration, as JSON for data-config.
     *
     * @return string
     */
    public function billingConfigJson(): string
    {
        return (string)$this->json->serialize([
            'endpoint' => $this->getUrl('quissly/billing/manage'),
            'reload' => $this->getUrl('quissly/billing/index'),
            'csrf' => ['form_key' => $this->getFormKey()],
            'text' => [
                'working' => (string)__('Working...'),
                'error' => (string)__('Could not reach the server. Try again in a minute.'),
                'payWaiting' => (string)__(
                    'Finish the payment in the tab that opened. This page updates by itself once Quissly has it.'
                ),
                'payTimeout' => (string)__('We have not seen the payment yet. Reload this page once you have paid.'),
                'close' => (string)__('Close'),
                'titleChange' => (string)__('Change your plan'),
                'titleTopup' => (string)__('Buy extra requests'),
                'titleCancel' => (string)__('Cancel your plan?'),
                'titlePay' => (string)__('Waiting for the payment'),
                'titleError' => (string)__('Something went wrong'),
                'confirmCancel' => (string)__('Cancel plan'),
            ],
        ]);
    }

    /**
     * Everything the page shows; null when Quissly could not be reached.
     *
     * @return array|null
     */
    public function view(): ?array
    {
        if ($this->view !== false) {
            return $this->view;
        }
        $this->view = null;
        if (!$this->onboarding->isConnected()) {
            return null;
        }
        $websiteId = $this->onboarding->websiteId();
        $plans = $this->billing->plans($websiteId);
        $subscriptions = $this->billing->subscriptions($websiteId);
        if ($plans === null || $subscriptions === null) {
            return null;
        }
        $currency = (string)($plans['currency'] ?? 'USD');
        $trialDays = (int)($plans['trial_days'] ?? 0);
        $open = !empty($plans['billing_open']);
        $eligible = is_array($subscriptions['trial_eligible'] ?? null) ? $subscriptions['trial_eligible'] : [];

        $cards = [];
        $discount = 0;
        foreach ($plans['plans'] as $plan) {
            $card = $this->card($plan, $currency, $trialDays, $open, $eligible);
            $cards[(string)$plan['id']] = $card + $this->cardWords($plan, $card, $currency, $trialDays, $eligible)
                + ['raw' => $plan];
            $discount = max($discount, (int)($plan['annual_discount_pct'] ?? 0));
        }
        $live = $this->billing->livePlans($subscriptions);

        $families = [];
        $meters = [];
        $extras = [];
        $windows = [];
        foreach (self::FAMILIES as $family) {
            $familyCards = array_values(array_filter($cards, static fn (array $c) => $c['family'] === $family));
            usort($familyCards, static fn (array $a, array $b) => $a['sort'] <=> $b['sort']);
            $plan = isset($live[$family]) ? $this->livePlan($live[$family], $cards, $currency) : null;
            $families[$family] = [
                'label' => $family === 'qchat' ? (string)__('Chat') : (string)__('Search'),
                'cards' => $familyCards,
                'plan' => $plan,
            ];
            if ($plan === null) {
                continue;
            }
            $usage = $this->usage($websiteId, $plan['id'], $family === 'qchat' ? 'QChat' : 'QSearch');
            if ($usage !== null) {
                $meters[] = $usage;
                $windows[] = [$usage['from'], $plan['period_end']];
                if ($usage['extra'] > 0 || $plan['extra'] !== null) {
                    $extras[] = $usage + ['plan' => $plan];
                }
            } elseif ($plan['extra'] !== null) {
                $extras[] = ['label' => $family === 'qchat' ? 'QChat' : 'QSearch', 'extra' => 0, 'plan' => $plan];
            }
        }

        $this->view = [
            'billing_open' => $open,
            'discount_pct' => $discount,
            'families' => $families,
            'meters' => $meters,
            'window' => $this->window($windows),
            'extras' => $extras,
            'invoices' => $this->invoices($websiteId),
        ];
        return $this->view;
    }

    /**
     * What a full plan card says, as Shopify's Settings cards do.
     *
     * The note under the price for each cadence, the trial pill and the checklist.
     *
     * @param array $plan The plan from GET /plans
     * @param array $card Setup's card for it
     * @param string $currency
     * @param int $trialDays
     * @param array $eligible
     * @return array
     */
    private function cardWords(array $plan, array $card, string $currency, int $trialDays, array $eligible): array
    {
        $family = (string)$plan['family'];
        $free = $card['free'];
        $quota = $plan['quotas'][0] ?? [];
        $requests = (int)($quota['requests_per_month'] ?? 0);
        $extra = $quota['extra_block'] ?? null;

        $feats = [$family === 'qchat' ? (string)__('Includes QChat') : (string)__('Includes QSearch')];
        if ($family === 'qchat' && $requests === 0) {
            $feats[] = (string)__('Human agents only - no AI messages');
        } else {
            $feats[] = $family === 'qchat'
                ? (string)__('%1 AI messages / month', number_format($requests))
                : (string)__('%1 search requests / month', number_format($requests));
        }
        if (is_array($extra)) {
            $feats[] = (string)__(
                'Extra requests: %1 per %2',
                $this->money((float)$extra['price'], $currency),
                number_format((int)$extra['requests'])
            );
            $feats[] = (string)__('Unused extra requests carry over');
        } else {
            $feats[] = (string)__('No extra requests');
        }

        return [
            'note_month' => $free ? (string)__('Free forever - no card required') : (string)__('Billed monthly'),
            'note_year' => $free
                ? (string)__('Free forever - no card required')
                : ($card['saving'] !== ''
                    ? (string)__('%1/mo billed monthly · save %2/yr', $card['monthly'], $card['saving'])
                    : (string)__('Billed yearly')),
            'trial' => !$free && $trialDays > 0 && ($eligible[$family] ?? true)
                ? (string)__('%1-day free trial', $trialDays)
                : '',
            'feats' => $feats,
        ];
    }

    /**
     * One live plan, in words, with the actions it allows.
     *
     * @param array $row {subscription, plan} from GET /subscriptions
     * @param array $cards Plan cards keyed by plan id
     * @param string $currency
     * @return array
     */
    private function livePlan(array $row, array $cards, string $currency): array
    {
        $sub = $row['subscription'];
        $card = $cards[(string)($sub['plan_id'] ?? '')] ?? null;
        $status = (string)($sub['status'] ?? '');
        $paddle = ($sub['channel'] ?? '') === 'paddle';
        $annual = ($sub['billing_cycle'] ?? '') === 'annual';
        $free = $card !== null && $card['free'];
        $ends = $this->date((string)($sub['current_period_end'] ?? ''));
        $cancelling = !empty($sub['cancel_after_period_end']);
        $pending = $cards[(string)($sub['pending_plan_id'] ?? '')] ?? null;
        $extra = $card['raw']['quotas'][0]['extra_block'] ?? null;
        $id = (string)($sub['id'] ?? '');

        $meta = [];
        if ($free) {
            $meta[] = (string)__('Free · no card needed');
        } elseif ($card !== null) {
            $meta[] = $annual
                ? (string)__('%1 / year', $card['annual_total'])
                : (string)__('%1 / month', $card['monthly']);
        }
        if ($ends !== '' && !$free) {
            $meta[] = $cancelling
                ? (string)__('ends %1', $ends)
                : ($status === 'trial' ? (string)__('trial ends %1', $ends) : (string)__('renews %1', $ends));
        }

        $banners = [];
        if ($status === 'trial' && !$cancelling) {
            $banners[] = ['tone' => 'info', 'text' => (string)__(
                'You are in a free trial. Charges begin when it ends, on %1.',
                $ends
            )];
        }
        if ($status === 'past_due') {
            $banners[] = ['tone' => 'critical', 'text' => (string)__(
                'Your last payment failed. Update your card to keep the plan.'
            ), 'op' => 'payment_method', 'action' => (string)__('Update card')];
        }
        if ($cancelling) {
            $banners[] = ['tone' => 'warning', 'text' => (string)__(
                'Your plan ends on %1. Nothing more is charged.',
                $ends
            ), 'op' => 'resume', 'action' => (string)__('Keep my plan')];
        }
        if ($pending !== null) {
            $banners[] = ['tone' => 'info', 'text' => (string)__(
                'Your plan changes to %1 on %2. You keep your current plan until then.',
                $pending['name'],
                $ends
            ), 'op' => 'abort', 'action' => (string)__('Undo the change')];
        }
        $promo = (string)($sub['promo_code'] ?? '');
        if ($promo !== '' && ($sub['promo_discount_amount'] ?? null) !== null) {
            $banners[] = ['tone' => 'success', 'text' => (string)__(
                'Promo %1: %2 off each payment.',
                $promo,
                $this->money((float)$sub['promo_discount_amount'], $currency)
            )];
        }

        return [
            'id' => $id,
            'name' => $card['name'] ?? $this->planName(
                (string)($row['plan']['family'] ?? ''),
                (string)($row['plan']['tier'] ?? '')
            ),
            'plan_id' => (string)($sub['plan_id'] ?? ''),
            'status_label' => [
                'trial' => (string)__('Trial'),
                'active' => (string)__('Active'),
                'past_due' => (string)__('Payment failed'),
            ][$status] ?? ucfirst($status),
            'chip' => ['trial' => 'running', 'active' => 'done'][$status] ?? 'failed',
            'meta' => implode(' · ', $meta),
            'banners' => $banners,
            'period_end' => (string)($sub['current_period_end'] ?? ''),
            'can_change' => $paddle && $pending === null && !$cancelling && $status !== 'past_due',
            'can_upgrade_free' => !$paddle,
            'can_cancel' => $paddle && !$cancelling,
            'can_card' => $paddle,
            'can_topup' => $paddle && is_array($extra) && $status === 'active',
            'extra' => is_array($extra) ? [
                'price' => $this->money((float)$extra['price'], $currency),
                'requests' => number_format((int)$extra['requests']),
                'label' => (string)__(
                    'Buy %1 more for %2',
                    number_format((int)$extra['requests']),
                    $this->money((float)$extra['price'], $currency)
                ),
            ] : null,
        ];
    }

    /**
     * One usage meter, as Shopify's UsageMeter draws it; null when it could not be read.
     *
     * @param int $websiteId
     * @param string $subscriptionId
     * @param string $label
     * @return array|null
     */
    private function usage(int $websiteId, string $subscriptionId, string $label): ?array
    {
        $usage = $subscriptionId === '' ? null : $this->billing->usage($websiteId, $subscriptionId);
        $service = $usage['services'][0] ?? null;
        if (!is_array($service)) {
            return null;
        }
        $limit = (int)($service['limit'] ?? 0);
        // granted is the plan's requests plus any bought this period.
        $granted = max($limit, (int)($service['granted'] ?? $limit));
        $used = (int)($service['used'] ?? 0);
        $remaining = (int)($service['remaining'] ?? max(0, $granted - $used));
        $pct = $granted > 0 ? min(100, $used / $granted * 100) : 0;
        $over = $granted > 0 && $remaining <= 0;
        $near = !$over && $granted > 0 && $pct >= 80;
        return [
            'label' => $label,
            'used' => number_format($used),
            'used_raw' => $used,
            'of' => $granted > 0 ? number_format($granted) : '',
            'of_raw' => $granted,
            'left' => $granted > 0 ? (string)__('%1 left', number_format($remaining)) : '',
            'out' => $remaining <= 0,
            'pct' => round($pct, 1),
            'tone' => $over ? 'is-over' : ($near ? 'is-near' : ''),
            'note' => $over
                ? (string)__('This month\'s requests are used up. Buy extra requests to keep answering.')
                : ($near ? (string)__('Approaching your included requests for this period.') : ''),
            'extra' => max(0, $granted - $limit),
            'from' => (string)($service['period_start'] ?? ''),
        ];
    }

    /**
     * "Current window: 2 Oct → 2 Nov", when the plans agree on one; '' otherwise.
     *
     * @param array $windows [period start, period end] per plan
     * @return string
     */
    private function window(array $windows): string
    {
        $windows = array_unique(array_map(static fn (array $w) => implode('|', $w), $windows));
        if (count($windows) !== 1) {
            return '';
        }
        [$from, $to] = explode('|', reset($windows));
        $from = $this->date($from, 'j M');
        $to = $this->date($to, 'j M');
        return $from !== '' && $to !== '' ? (string)__('Current window: %1 → %2', $from, $to) : '';
    }

    /**
     * The store's last invoices, newest first; null when they could not be read.
     *
     * @param int $websiteId
     * @return array|null
     */
    private function invoices(int $websiteId): ?array
    {
        $list = $this->billing->invoices($websiteId);
        if ($list === null) {
            return null;
        }
        $rows = [];
        foreach ($list['invoices'] as $invoice) {
            $rows[] = [
                'id' => (string)($invoice['id'] ?? ''),
                'date' => $this->date((string)($invoice['issued_at'] ?? $invoice['period_start'] ?? '')),
                'period' => (string)__(
                    '%1 - %2',
                    $this->date((string)($invoice['period_start'] ?? '')),
                    $this->date((string)($invoice['period_end'] ?? ''))
                ),
                'amount' => $this->money(
                    (float)($invoice['amount_charged'] ?? 0),
                    (string)($invoice['currency'] ?? 'USD')
                ),
                'status' => ucfirst(str_replace('_', ' ', (string)($invoice['status'] ?? ''))),
                'pdf' => ($invoice['channel'] ?? '') === 'paddle',
            ];
        }
        return $rows;
    }

    /**
     * A date as the admin reads it ("2 Nov 2026"), '' when there is none.
     *
     * @param string $iso
     * @param string $format
     * @return string
     */
    public function date(string $iso, string $format = 'j M Y'): string
    {
        if ($iso === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($iso))->format($format);
        } catch (\Exception $e) {
            return '';
        }
    }
}
