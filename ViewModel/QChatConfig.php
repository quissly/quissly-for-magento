<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Quissly\Search\Model\Config\Settings;

/**
 * View model for the QChat widget embed.
 *
 * The widget renders only when BOTH hold for the current store's website:
 * the qchat toggle is on (default OFF) and an agent id is configured. The
 * agent id is the chat middleware's public Service id - safe in markup by
 * design; credentials never appear here.
 *
 * Output is identical for every shopper of a store (config-derived only),
 * so the emitting block stays fully FPC-cacheable.
 */
class QChatConfig implements ArgumentInterface
{
    /**
     * @param Settings $settings
     */
    public function __construct(
        private readonly Settings $settings
    ) {
    }

    /**
     * Whether the embed should render for the current website.
     *
     * @return bool
     */
    public function shouldRender(): bool
    {
        return $this->settings->isQchatEnabled() && $this->settings->qchatAgentId() !== null;
    }

    /**
     * The public agent id for the current website ('' when not renderable).
     *
     * @return string
     */
    public function agentId(): string
    {
        return (string)($this->settings->qchatAgentId() ?? '');
    }

    /**
     * The widget bundle URL (constant CDN origin, whitelisted in csp_whitelist.xml).
     *
     * @return string
     */
    public function scriptUrl(): string
    {
        return $this->settings->qchatScriptUrl();
    }
}
