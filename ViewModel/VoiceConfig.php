<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\ViewModel;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Api\MediaSearchClient;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Health\MediaCapability;

/**
 * View model for the voice-search widget.
 *
 * Renders only when the website's voice toggle is ON. Output is config-derived
 * only (same for every shopper) - the emitting block stays FPC-cacheable; the
 * per-shopper part (audio) happens client-side against the anonymous proxy.
 */
class VoiceConfig implements ArgumentInterface
{
    /**
     * @param Settings $settings
     * @param UrlInterface $url
     * @param MediaCapability $capability
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly UrlInterface $url,
        private readonly MediaCapability $capability,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Whether the widget should render for the current website.
     *
     * Two switches must both be on: the merchant's toggle AND Quissly's
     * per-tenant enablement. A control that cannot work is never drawn.
     *
     * @return bool
     */
    public function shouldRender(): bool
    {
        if (!$this->settings->isVoiceEnabled()) {
            return false;
        }
        return $this->capability->isUsable($this->websiteId(), MediaSearchClient::KIND_VOICE);
    }

    /**
     * Current website id (toggles and gates are website-scoped).
     *
     * @return int|null
     */
    private function websiteId(): ?int
    {
        try {
            return (int)$this->storeManager->getStore()->getWebsiteId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The anonymous voice proxy endpoint.
     *
     * @return string
     */
    public function endpoint(): string
    {
        return $this->url->getUrl('quissly/voice/search');
    }
}
