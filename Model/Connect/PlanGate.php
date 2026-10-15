<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Connect;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\FlagManager;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Which feature switches a website's plan leaves out.
 *
 * A website on a Search plan only gets QChat's switch disabled ("not in your
 * plan"), one on a Chat plan the search switches - search and everything that
 * only works with it. A disabled switch counts as off everywhere
 * (Plugin\Config\PlanLock on Settings).
 *
 * What the plan includes, best known first: Quissly's live plans (GET
 * /subscriptions, kept per website by remember(); asked by Configuration,
 * Billing, Setup and the quissly_plan cron), plus a plan chosen in Setup for
 * manual invoice (Quissly does not know of that one yet); while Quissly's answer
 * has never been read, the plan chosen in Setup. Setup is the default website's.
 * Nothing known (a store connected before Setup, a Quissly outage at Setup)
 * disables nothing - a store already live is never switched off on a guess.
 */
class PlanGate
{
    /** Quissly's last answer, per website: {families, at}. */
    public const FLAG_PREFIX = 'quissly_plan_w';

    /** Quissly Setup's record (Onboarding): the plan chosen there, and a manual invoice. */
    private const ONBOARDING_FLAG = 'quissly_onboarding';

    /** How long Quissly's answer is kept before the cron asks again. */
    public const MAX_AGE = 600;

    /** The products a plan can include. */
    private const SERVICES = ['qsearch', 'qchat'];

    /** Settings that need a Search plan: search itself and what only works with it. */
    public const SEARCH_PATHS = [
        'quissly/features/enable_search',
        'quissly/features/enable_overlay',
        'quissly/features/overlay_suggestions',
        'quissly/features/overlay_recent',
        'quissly/features/enable_quick',
        'quissly/features/enable_voice',
        'quissly/features/enable_image',
        'quissly/most_loved/enabled',
    ];

    /** Settings that need a Chat plan. */
    public const CHAT_PATHS = ['quissly/features/enable_qchat'];

    /** @var array<int, array<string, string>> this request's answers, per website */
    private array $locked = [];

    /** @var array<int, bool> websites already asked in this request */
    private array $asked = [];

    /**
     * @param FlagManager $flagManager
     * @param StoreManagerInterface $storeManager
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * The disabled settings for what a plan includes.
     *
     * @param string[] $families the plan's products (qsearch, qchat); none = not known
     * @return array<string, string> config path => the product it needs
     */
    public function lockedFor(array $families): array
    {
        $families = array_intersect(self::SERVICES, $families);
        if ($families === []) {
            return [];
        }
        $locked = [];
        if (!in_array('qsearch', $families, true)) {
            $locked += array_fill_keys(self::SEARCH_PATHS, 'qsearch');
        }
        if (!in_array('qchat', $families, true)) {
            $locked += array_fill_keys(self::CHAT_PATHS, 'qchat');
        }
        return $locked;
    }

    /**
     * What the plan includes, from what is known.
     *
     * @param string[]|null $live Quissly's live plans' products; null = never read
     * @param string $invoice the product of a plan chosen for manual invoice, or ''
     * @param string[] $setup the products of the plan chosen in Setup
     * @return string[]
     */
    public function families(?array $live, string $invoice, array $setup): array
    {
        $known = $live ?? $setup;
        if ($invoice !== '') {
            $known[] = $invoice;
        }
        return array_values(array_intersect(self::SERVICES, array_unique($known)));
    }

    /**
     * The settings a website's plan leaves out, read once a request.
     *
     * @param int $websiteId
     * @return array<string, string> config path => the product it needs
     */
    public function locked(int $websiteId): array
    {
        if (!isset($this->locked[$websiteId])) {
            $kept = $this->flagManager->getFlagData(self::FLAG_PREFIX . $websiteId);
            $setup = $websiteId === $this->setupWebsiteId()
                ? (array)$this->flagManager->getFlagData(self::ONBOARDING_FLAG)
                : [];
            $this->locked[$websiteId] = $this->lockedFor($this->families(
                is_array($kept) && is_array($kept['families'] ?? null) ? $kept['families'] : null,
                (string)($setup['invoice']['family'] ?? ''),
                array_values(array_intersect(self::SERVICES, (array)($setup['services'] ?? [])))
            ));
        }
        return $this->locked[$websiteId];
    }

    /**
     * Keeps Quissly's answer about a website's plans (StoreBilling::subscriptions()).
     *
     * @param int $websiteId
     * @param array|null $subscriptions null is no answer: the last one is kept
     * @param int $now
     * @return void
     */
    public function remember(int $websiteId, ?array $subscriptions, int $now): void
    {
        if ($subscriptions === null) {
            return;
        }
        $families = [];
        foreach ((array)($subscriptions['subscriptions'] ?? []) as $row) {
            $status = (string)($row['subscription']['status'] ?? '');
            $family = (string)($row['plan']['family'] ?? '');
            if ($family !== '' && in_array($status, \Quissly\Search\Model\Api\StoreBilling::LIVE_STATUSES, true)) {
                $families[$family] = true;
            }
        }
        $kept = $this->flagManager->getFlagData(self::FLAG_PREFIX . $websiteId);
        $before = is_array($kept) && is_array($kept['families'] ?? null) ? $kept['families'] : null;
        $this->flagManager->saveFlag(
            self::FLAG_PREFIX . $websiteId,
            ['families' => array_keys($families), 'at' => $now]
        );
        unset($this->locked[$websiteId]);
        if ($before !== array_keys($families)) {
            // The storefront changes with the plan (the chat, search) and no setting is saved, so
            // the pages cached under the old plan go too (as Backend\StorefrontSetting does).
            $this->cacheTypeList->cleanType('full_page');
            $this->cacheTypeList->cleanType('block_html');
        }
    }

    /**
     * Ask Quissly about a website's plans: always (once a request), or once its last answer is MAX_AGE old.
     *
     * @param int $websiteId
     * @param callable $ask fn(): ?array - StoreBilling::subscriptions() for the website
     * @param bool $always
     * @param int $now
     * @return void
     */
    public function refresh(int $websiteId, callable $ask, bool $always, int $now): void
    {
        if (isset($this->asked[$websiteId])) {
            return;
        }
        if (!$always) {
            $kept = $this->flagManager->getFlagData(self::FLAG_PREFIX . $websiteId);
            if (is_array($kept) && (int)($kept['at'] ?? 0) + self::MAX_AGE > $now) {
                return;
            }
        }
        $this->asked[$websiteId] = true;
        $this->remember($websiteId, $ask(), $now);
    }

    /**
     * The website Quissly Setup is for: the default store view's.
     *
     * @return int
     */
    private function setupWebsiteId(): int
    {
        try {
            return (int)$this->storeManager->getDefaultStoreView()->getWebsiteId();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
