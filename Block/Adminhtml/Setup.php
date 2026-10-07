<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Block\Adminhtml;

use Laminas\Uri\UriFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Api\StoreBilling;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Connect\Onboarding;
use Quissly\Search\Model\Connect\SetupInput;
use Quissly\Search\Model\Connect\StoreFacts;

/**
 * Quissly Setup: what the onboarding page shows.
 *
 * Plan figures are formatted here, in PHP, so every word on the page goes
 * through Magento's translations and the shared script stays free of them.
 */
class Setup extends Template
{
    /** @var array|null|false Plan view, built once per request; false = not built yet */
    private $planView = false;

    /** @var string|null Description draft, built once per request */
    private ?string $description = null;

    /**
     * @param Context $context
     * @param Onboarding $onboarding
     * @param StoreBilling $billing
     * @param Settings $settings
     * @param StoreManagerInterface $storeManager
     * @param AuthSession $authSession
     * @param Json $json
     * @param StoreFacts $storeFacts
     * @param SetupInput $input
     * @param array $data
     */
    public function __construct(
        Context $context,
        protected readonly Onboarding $onboarding,
        protected readonly StoreBilling $billing,
        private readonly Settings $settings,
        private readonly StoreManagerInterface $storeManager,
        private readonly AuthSession $authSession,
        protected readonly Json $json,
        private readonly StoreFacts $storeFacts,
        private readonly SetupInput $input,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The step the store is on.
     *
     * @return string
     */
    public function step(): string
    {
        return $this->onboarding->step();
    }

    /**
     * The page's script configuration, as JSON for data-config.
     *
     * @return string
     */
    public function configJson(): string
    {
        $step = $this->step();
        return (string)$this->json->serialize([
            'step' => $step,
            'reached' => $step,
            'reload' => $this->getUrl('quissly/setup/index'),
            'csrf' => ['form_key' => $this->getFormKey()],
            'endpoints' => [
                'connect' => ['url' => $this->getUrl('quissly/connect/index'), 'params' => (object)[]],
                'plan' => ['url' => $this->getUrl('quissly/setup/plan'), 'params' => (object)[]],
                'status' => ['url' => $this->getUrl('quissly/setup/status'), 'params' => (object)[]],
                'finish' => ['url' => $this->getUrl('quissly/setup/finish'), 'params' => (object)[]],
            ],
            'text' => [
                'connecting' => (string)__('Creating your Quissly account...'),
                'saving' => (string)__('Saving...'),
                'goingLive' => (string)__('Going live...'),
                'pickPlan' => (string)__('Pick a plan to continue'),
                'saveYear' => (string)__('Save %1/yr'),
                'saveDefault' => ($this->planView()['discount_pct'] ?? 0) > 0
                    ? (string)__('Save %1%', $this->planView()['discount_pct'])
                    : '',
                'payTimeout' => (string)__(
                    'We have not seen the payment yet. Finish it in the other tab, then press "I have paid".'
                ),
                'payNotYet' => (string)__('Payment not received yet.'),
                'error' => (string)__('Could not reach the server. Try again in a minute.'),
                'compare' => (string)__('Compare all features'),
                'compareBack' => (string)__('← Back to plan cards'),
                'emailRequired' => (string)__('Email is required.'),
                'emailInvalid' => (string)__('Enter a valid email address, like name@example.com.'),
                'emailTooLong' => (string)__('Email address is too long.'),
                'emailPublic' => (string)__('Use an address on a real, public domain.'),
                'nameInvalid' => (string)__('Use letters, numbers, spaces and hyphens in the store name.'),
                'chipWorking' => (string)__('Setting up...'),
                'chipReady' => (string)__('Ready'),
                'chipFailed' => (string)__('Needs attention'),
                'states' => [
                    'done' => (string)__('Done'),
                    'running' => (string)__('Running'),
                    'pending' => (string)__('Waiting'),
                    'failed' => (string)__('Failed'),
                ],
            ],
        ]);
    }

    /**
     * The Quissly wordmark, cream on ink, for the brand strip.
     *
     * @return string
     */
    public function logoUrl(): string
    {
        return $this->getViewFileUrl('Quissly_Search::images/quissly-wordmark.svg');
    }

    /**
     * The signed-in admin's email, the account's suggested address.
     *
     * @return string
     */
    public function adminEmail(): string
    {
        $user = $this->authSession->getUser();
        return $user === null ? '' : (string)$user->getEmail();
    }

    /**
     * The store's trading name as a workspace name the form accepts (the Shopify app's suggestion).
     *
     * @return string
     */
    public function storeName(): string
    {
        return $this->input->suggestName($this->settings->storeDisplayName($this->onboarding->websiteId()));
    }

    /**
     * The data-* attributes the script reads off a plan, for a card and its comparison column alike.
     *
     * @param array $card
     * @return string Escaped attribute markup
     */
    public function planAttributes(array $card): string
    {
        $attributes = [
            'data-plan-id' => $card['id'],
            'data-family' => $card['family'],
            'data-free' => $card['free'] ? '1' : '0',
            'data-saving' => $card['saving'],
            'data-cta' => $card['cta'],
            'data-note-monthly' => $card['note_monthly'],
            'data-note-annual' => $card['note_annual'],
        ];
        $html = 'data-q-plan role="radio" tabindex="-1" aria-checked="false" aria-disabled="'
            . ($card['disabled'] ? 'true' : 'false') . '"';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . $this->_escaper->escapeHtmlAttr((string)$value) . '"';
        }
        return $html;
    }

    /**
     * The workspace description, drafted from the store's own facts (the Shopify app's draft).
     *
     * @return string
     */
    public function description(): string
    {
        if ($this->description === null) {
            $this->description = $this->storeFacts->description($this->onboarding->websiteId());
        }
        return $this->description;
    }

    /**
     * The storefront host Quissly registers the account against.
     *
     * @return string
     */
    public function domain(): string
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            return $store === null ? '' : (string)UriFactory::factory((string)$store->getBaseUrl())->getHost();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The email the store was connected with.
     *
     * @return string
     */
    public function accountEmail(): string
    {
        return (string)$this->settings->accountEmail($this->onboarding->websiteId());
    }

    /**
     * Everything the plan step shows; null when Quissly's plans could not be loaded.
     *
     * @return array{billing_open: bool, families: array<string, array>, current: array|null}|null
     */
    public function planView(): ?array
    {
        if ($this->planView !== false) {
            return $this->planView;
        }
        $this->planView = null;
        if (!$this->onboarding->isConnected()) {
            return null;
        }
        $websiteId = $this->onboarding->websiteId();
        $plans = $this->billing->plans($websiteId);
        if ($plans === null) {
            return null;
        }
        $subscriptions = $this->billing->subscriptions($websiteId);
        $trialEligible = is_array($subscriptions['trial_eligible'] ?? null) ? $subscriptions['trial_eligible'] : [];
        $live = $subscriptions === null ? [] : $this->billing->livePlans($subscriptions);
        $currency = (string)($plans['currency'] ?? 'USD');
        $trialDays = (int)($plans['trial_days'] ?? 0);
        $open = !empty($plans['billing_open']);

        $families = ['qsearch' => [], 'qchat' => []];
        foreach ($plans['plans'] as $plan) {
            $family = (string)($plan['family'] ?? '');
            if (!isset($families[$family])) {
                continue;
            }
            $families[$family][] = $this->card($plan, $currency, $trialDays, $open, $trialEligible);
        }
        foreach ($families as $family => $cards) {
            usort($cards, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
            $families[$family] = $cards;
        }

        $current = null;
        foreach ($live as $row) {
            $current = [
                'name' => $this->planName((string)($row['plan']['family'] ?? ''), (string)($row['plan']['tier'] ?? '')),
                'status' => (string)($row['subscription']['status'] ?? ''),
            ];
            break;
        }

        $this->planView = [
            'billing_open' => $open,
            'families' => $families,
            'current' => $current,
            'discount_pct' => (int)($plans['plans'][0]['annual_discount_pct'] ?? 0),
        ];
        return $this->planView;
    }

    /**
     * One plan card, every figure already in words.
     *
     * @param array $plan
     * @param string $currency
     * @param int $trialDays
     * @param bool $open
     * @param array $trialEligible
     * @return array
     */
    protected function card(array $plan, string $currency, int $trialDays, bool $open, array $trialEligible): array
    {
        $family = (string)$plan['family'];
        $name = $this->planName($family, (string)($plan['tier'] ?? ''));
        $free = !empty($plan['is_free']);
        $monthly = (float)($plan['price_monthly'] ?? 0);
        $annual = (float)($plan['price_annual'] ?? 0);
        $monthlyText = $this->money($monthly, $currency);
        $annualMonthText = $this->money($annual / 12, $currency);
        $saving = (int)round($monthly * 12 - $annual);
        $trial = !$free && $trialDays > 0 && ($trialEligible[$family] ?? true);

        $quota = $plan['quotas'][0] ?? [];
        $requests = (int)($quota['requests_per_month'] ?? 0);
        $extra = $quota['extra_block'] ?? null;
        if ($family === 'qchat' && $requests === 0) {
            $usage = [(string)__('Human agents only'), (string)__('no AI messages')];
        } else {
            $usage = [
                number_format($requests),
                $family === 'qchat' ? (string)__('AI messages / mo') : (string)__('searches / mo'),
            ];
        }
        $extraFigure = is_array($extra)
            ? [
                (string)__(
                    '%1 per %2',
                    $this->money((float)$extra['price'], $currency),
                    number_format((int)$extra['requests'])
                ),
                (string)__('extra requests'),
            ]
            : [(string)__('None'), (string)__('extra requests')];

        return [
            'id' => (string)$plan['id'],
            'family' => $family,
            'name' => $name,
            'free' => $free,
            'popular' => !empty($plan['is_popular']),
            'disabled' => !$free && !$open,
            'monthly' => $monthlyText,
            'annual_month' => $annualMonthText,
            'monthly_note' => $free
                ? (string)__('Free forever - no card')
                : ($trial ? (string)__('%1-day free trial', $trialDays) : (string)__('billed monthly')),
            'annual_note' => $free
                ? (string)__('Free forever - no card')
                : (string)__('billed %1 yearly', $this->money($annual, $currency)),
            'saving' => $free || $saving <= 0 ? '' : $this->money((float)$saving, $currency),
            'figures' => [$usage, $extraFigure],
            'annual_total' => $free ? '—' : $this->money($annual, $currency),
            'trial_label' => $free
                ? (string)__('Free plan')
                : ($trial ? (string)__('%1 days', $trialDays) : '—'),
            'cta' => $free ? (string)__('Start the free plan') : (string)__('Continue with %1', $name),
            'note_monthly' => $free
                ? (string)__('%1 · free', $name)
                : (string)__('%1 · %2/mo · billed monthly', $name, $monthlyText),
            'note_annual' => $free
                ? (string)__('%1 · free', $name)
                : (string)__('%1 · %2/mo · billed yearly', $name, $annualMonthText),
            'sort' => $monthly,
        ];
    }

    /**
     * "QSearch Growth", from the plan's family and tier.
     *
     * @param string $family
     * @param string $tier
     * @return string
     */
    public function planName(string $family, string $tier): string
    {
        $families = ['qsearch' => 'QSearch', 'qchat' => 'QChat'];
        $tiers = [
            'free' => __('Free'),
            'basic' => __('Basic'),
            'starter' => __('Starter'),
            'growth' => __('Growth'),
            'scale' => __('Scale'),
        ];
        return trim(($families[$family] ?? ucfirst($family)) . ' ' . (string)($tiers[$tier] ?? ucfirst($tier)));
    }

    /**
     * A price as the merchant reads it: "$89", "$75.65".
     *
     * @param float $amount
     * @param string $currency
     * @return string
     */
    protected function money(float $amount, string $currency): string
    {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $whole = abs($amount - round($amount)) < 0.005;
        $number = number_format($amount, $whole ? 0 : 2);
        return isset($symbols[$currency]) ? $symbols[$currency] . $number : $currency . ' ' . $number;
    }
}
