<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;
use Quissly\Search\Model\Sync\SyncCompletion;

/**
 * Refuses to switch a feature ON before the first catalog sync has completed.
 *
 * Turning search on against an empty index is the worst outcome available:
 * qsearch answers 200 with no documents, the fallback matrix correctly reads
 * that as a real answer, and every search on a healthy store returns "no
 * results" - with nothing anywhere reporting a fault. The first-sync gate
 * already prevents the storefront acting on it; this stops the merchant
 * setting up that state in the first place, and says why.
 *
 * Only the OFF -> ON transition is refused. A feature already running is never
 * switched off by this class, whatever the queue is doing afterwards: a sync
 * that fails next week must not silently disable a working storefront.
 */
class RequiresFirstSync extends StorefrontSetting
{
    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param SyncCompletion $completion
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        private readonly SyncCompletion $completion,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }
    /**
     * @inheritdoc
     */
    public function beforeSave()
    {
        // Only interested in someone turning this on.
        if (!$this->isTurningOn()) {
            return parent::beforeSave();
        }

        $websiteId = $this->resolvedWebsiteId();
        if ($this->completion->isComplete($websiteId)) {
            return parent::beforeSave();
        }

        throw new LocalizedException($this->reason($this->completion->progressSummary($websiteId)));
    }

    /**
     * Whether this save turns the feature on from an off state.
     *
     * @return bool
     */
    private function isTurningOn(): bool
    {
        if ((string)$this->getValue() !== '1') {
            return false;
        }

        // getOldValue() reads the value this scope resolves to today, so a
        // feature already on - whether set here or inherited - is left alone.
        return (string)$this->getOldValue() !== '1';
    }

    /**
     * The website this save applies to.
     *
     * @return int
     */
    private function resolvedWebsiteId(): int
    {
        $scoped = (int)$this->getScopeId();
        if ($scoped > 0) {
            return $scoped;
        }

        // Default scope is not a website, and website 0 has no gate - checking
        // it would refuse the save forever on every store. The default
        // website's gate is the one that answers for this scope, matching how
        // credentials resolve (website scope, default fallback).
        try {
            return (int)$this->storeManager->getDefaultStoreView()->getWebsiteId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Why the save was refused, in terms the merchant can act on.
     *
     * @param array $progress
     * @return \Magento\Framework\Phrase
     */
    private function reason(array $progress)
    {
        // No markup in here. Magento renders config-save errors through
        // EscapeRenderer (app/etc/di.xml -> escape_renderer), which escapes with
        // no allowed tags, so an anchor would reach the merchant as literal
        // "<a href=...>" text. The clickable route lives on the field's own note
        // (SyncGatedToggle), which this class cannot reach; here we name the
        // menu path in words so it is still followable.
        $total = (int)($progress['total'] ?? 0);
        $ok = (int)($progress['ok'] ?? 0);
        $pending = (int)($progress['pending'] ?? 0);

        if ($total > 0) {
            return __(
                'The first catalog sync has not finished - %1 of %2 products sent, %3 still '
                . 'queued. Turning this on now would search an incomplete catalog. Watch it '
                . 'finish under Quissly > Dashboard in the menu, then switch this on.',
                $ok,
                $total,
                $pending
            );
        }

        return __(
            'Run the first catalog sync before switching this on. Until Quissly has your '
            . 'products, searches would return nothing at all. Start it under '
            . 'Quissly > Dashboard in the menu.'
        );
    }
}
