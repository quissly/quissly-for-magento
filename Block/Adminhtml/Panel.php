<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Api\PanelSession;
use Quissly\Search\Model\Config\Settings;

/**
 * Builds the embedded panel's iframe URL.
 *
 * The session is opened server-side, so the store's API key never reaches the
 * browser - only the short-lived tokens the panel needs to bootstrap itself.
 */
class Panel extends Template
{
    /**
     * @param Context $context
     * @param PanelSession $session
     * @param Settings $settings
     * @param StoreManagerInterface $storeManager
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly PanelSession $session,
        private readonly Settings $settings,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @var array|null Memoized so one render performs one key exchange. */
    private ?array $sessionResult = null;

    /**
     * The panel session for this render.
     *
     * The template asks for embedUrl() and then, on failure, for
     * unavailableReason() - so an unmemoized call meant a misconfigured store
     * waited through TWO 10-second timeouts before seeing the error, and the
     * store's API key was exchanged twice where once would do.
     *
     * @return array
     */
    private function session(): array
    {
        if ($this->sessionResult === null) {
            $this->sessionResult = $this->session->open($this->websiteId());
        }

        return $this->sessionResult;
    }

    /**
     * The panel URL with a bootstrapped session, or null when unavailable.
     *
     * @return string|null
     */
    public function embedUrl(): ?string
    {
        $result = $this->session();
        if (!$result['ok']) {
            return null;
        }

        return $this->settings->panelUrl($this->websiteId()) . '/?' . http_build_query([
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
        ]);
    }

    /**
     * Why the panel could not be embedded, in the merchant's terms.
     *
     * @return string
     */
    /**
     * Whether this store has credentials at all.
     *
     * Distinguishes "not connected yet" from "connected but the panel is
     * refusing": only the second is worth offering a direct link for, since
     * the link cannot sign anyone in without credentials either.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->settings->isConfigured($this->websiteId());
    }

    public function unavailableReason(): string
    {
        $result = $this->session();
        switch ($result['error']) {
            case 'not_configured':
                // This store has simply not connected yet. The old wording read as
                // an instruction to go and find two values by hand, which is exactly
                // what Connect exists to avoid.
                return (string)__(
                    'This store is not connected to Quissly yet. Your panel will load here '
                    . 'automatically once it is. Go to Stores > Configuration > Quissly and '
                    . 'press Connect to Quissly to get started.'
                );
            case 'rejected':
                // Naming the host matters: the commonest cause of a refusal is
                // credentials that are valid somewhere else. Blaming the email
                // outright sent us hunting the wrong thing for twenty minutes
                // when the real fault was a console URL pointing elsewhere.
                return (string)__(
                    'Quissly refused the sign-in at %1. Check that this store\'s account '
                    . 'email and project id belong to that Quissly environment - the account '
                    . 'Quissly created for this store, not a personal login.',
                    $this->settings->consoleUrl($this->websiteId())
                );
            case 'transport_error':
                return (string)__(
                    'Could not reach Quissly at %1. Check the connection and try again.',
                    $this->settings->consoleUrl($this->websiteId())
                );
            default:
                return (string)__('The panel is unavailable right now.');
        }
    }

    /**
     * The panel in its own tab, for when embedding is not possible.
     *
     * @return string
     */
    public function directUrl(): string
    {
        return $this->settings->panelUrl($this->websiteId());
    }

    /**
     * Current admin store context's website.
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
}
