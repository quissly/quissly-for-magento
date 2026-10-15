<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Plugin\Config;

use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Connect\PlanGate;

/**
 * A feature the website's plan leaves out (Model\Connect\PlanGate) is off,
 * whatever its switch says: one place, so search, the overlay, the chat widget,
 * the media endpoints and the Dashboard all agree. The saved value is kept for
 * when the plan includes the feature again.
 */
class PlanLock
{
    /**
     * @param PlanGate $planGate
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly PlanGate $planGate,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Search (enable_search).
     *
     * @param Settings $subject
     * @param bool $result
     * @param int|null $websiteId
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsSearchEnabled(Settings $subject, bool $result, ?int $websiteId = null): bool
    {
        return $this->allowed($result, 'quissly/features/enable_search', $websiteId);
    }

    /**
     * The search overlay.
     *
     * @param Settings $subject
     * @param bool $result
     * @param int|null $websiteId
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsOverlayEnabled(Settings $subject, bool $result, ?int $websiteId = null): bool
    {
        return $this->allowed($result, 'quissly/features/enable_overlay', $websiteId);
    }

    /**
     * The suggestion buttons under the overlay's bar.
     *
     * @param Settings $subject
     * @param bool $result
     * @param int|null $websiteId
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsOverlaySuggestionsEnabled(Settings $subject, bool $result, ?int $websiteId = null): bool
    {
        return $this->allowed($result, 'quissly/features/overlay_suggestions', $websiteId);
    }

    /**
     * The overlay's recent searches.
     *
     * @param Settings $subject
     * @param bool $result
     * @param int|null $websiteId
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsOverlayRecentEnabled(Settings $subject, bool $result, ?int $websiteId = null): bool
    {
        return $this->allowed($result, 'quissly/features/overlay_recent', $websiteId);
    }

    /**
     * Most loved products.
     *
     * @param Settings $subject
     * @param bool $result
     * @param int|null $websiteId
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsMostLovedEnabled(Settings $subject, bool $result, ?int $websiteId = null): bool
    {
        return $this->allowed($result, 'quissly/most_loved/enabled', $websiteId);
    }

    /**
     * Voice search.
     *
     * @param Settings $subject
     * @param bool $result
     * @param int|null $websiteId
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsVoiceEnabled(Settings $subject, bool $result, ?int $websiteId = null): bool
    {
        return $this->allowed($result, 'quissly/features/enable_voice', $websiteId);
    }

    /**
     * Image search.
     *
     * @param Settings $subject
     * @param bool $result
     * @param int|null $websiteId
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsImageEnabled(Settings $subject, bool $result, ?int $websiteId = null): bool
    {
        return $this->allowed($result, 'quissly/features/enable_image', $websiteId);
    }

    /**
     * Quick recommendations.
     *
     * @param Settings $subject
     * @param bool $result
     * @param int|null $websiteId
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsQuickEnabled(Settings $subject, bool $result, ?int $websiteId = null): bool
    {
        return $this->allowed($result, 'quissly/features/enable_quick', $websiteId);
    }

    /**
     * The QChat widget.
     *
     * @param Settings $subject
     * @param bool $result
     * @param int|null $websiteId
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsQchatEnabled(Settings $subject, bool $result, ?int $websiteId = null): bool
    {
        return $this->allowed($result, 'quissly/features/enable_qchat', $websiteId);
    }

    /**
     * A switched-on feature stays on unless the website's plan leaves it out.
     *
     * @param bool $result
     * @param string $path
     * @param int|null $websiteId
     * @return bool
     */
    private function allowed(bool $result, string $path, ?int $websiteId): bool
    {
        if (!$result) {
            return false;
        }
        try {
            $websiteId ??= (int)$this->storeManager->getStore()->getWebsiteId();
        } catch (\Throwable $e) {
            return true;
        }
        return !isset($this->planGate->locked($websiteId)[$path]);
    }
}
