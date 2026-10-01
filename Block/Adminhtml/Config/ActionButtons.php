<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Block\Adminhtml\Config;

use Magento\Backend\Model\Auth\Session;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Connect\ConnectHold;
use Quissly\Search\Model\Health\HealthRecorder;
use Quissly\Search\Model\Sync\FirstSyncGate;

/**
 * Renders the Generate Keys + Test Connection controls and the public-key
 * display inside the system config form, aware of the current scope
 * (default vs a specific website).
 */
class ActionButtons extends Field
{
    /**
     * @param Context $context
     * @param Settings $settings
     * @param FirstSyncGate $gate
     * @param HealthRecorder $health
     * @param Session $authSession
     * @param ConnectHold $hold
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Settings $settings,
        private readonly FirstSyncGate $gate,
        private readonly HealthRecorder $health,
        private readonly Session $authSession,
        private readonly ConnectHold $hold,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Search-interception status for a website.
     *
     * Mirrors the plugin's gate logic EXACTLY (never leave active-vs-inactive
     * invisible).
     *
     * @param int $websiteId
     * @return array{state: string, detail: string}
     */
    /**
     * Whether this scope already has credentials.
     *
     * Gates the connect control: creating a second account would strand
     * everything already synced to the first.
     *
     * @return bool
     */
    private function isConnected(): bool
    {
        $websiteId = (int)$this->getRequest()->getParam('website', 0);
        return $this->settings->apiToken($websiteId === 0 ? null : $websiteId) !== null;
    }

    /**
     * A sensible default for the Quissly account address.
     *
     * The signed-in admin's own email: it is the person doing the connecting,
     * and it becomes the identity the embedded panel signs in as. Offered
     * rather than imposed, because the account it creates is permanent.
     *
     * @return string
     */
    private function suggestedEmail(): string
    {
        try {
            return (string)$this->authSession->getUser()->getEmail();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Whether interception is live for this website, and why not if it is not.
     *
     * @param int $websiteId
     * @return array
     */
    private function searchStatus(int $websiteId): array
    {
        if (!$this->settings->isSearchEnabled($websiteId)) {
            return ['state' => 'INACTIVE', 'detail' => (string)__('AI Search is disabled.')];
        }
        if (!$this->settings->isConfigured($websiteId)) {
            return ['state' => 'INACTIVE', 'detail' => (string)__('Credentials incomplete (token + keys required).')];
        }
        if (!$this->gate->isOpen($websiteId === 0 ? 1 : $websiteId)) {
            return ['state' => 'INACTIVE', 'detail' => (string)__('Waiting for the first full catalog sync.')];
        }
        $failure = $this->health->currentFailure($websiteId === 0 ? 1 : $websiteId);
        if ($failure !== null) {
            return ['state' => 'DEGRADED', 'detail' => (string)__(
                'Shoppers see native search; last connection failure: %1',
                $failure['code']
            )];
        }
        return ['state' => 'ACTIVE', 'detail' => (string)__('Storefront search is served by Quissly.')];
    }

    /**
     * @inheritdoc
     */
    public function render(AbstractElement $element)
    {
        // Buttons span the row; no scope inheritance checkbox applies to them.
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->getLayout()
            ->createBlock(\Magento\Backend\Block\Template::class)
            ->setTemplate('Quissly_Search::config/action-buttons.phtml')
            ->setData([
                'connect_url' => $this->getUrl('quissly/connect/index'),
                // Hides the connect control once credentials exist: creating a
                // second account would strand everything synced to the first.
                'is_connected' => $this->isConnected(),
                'suggested_email' => $this->suggestedEmail(),
                'website_id' => (int)$this->getRequest()->getParam('website', 0),
                // Shown read-only once connected: the one identifier a merchant
                // ever needs to quote to support.
                'project_id' => (string)$this->settings->projectId(
                    (int)$this->getRequest()->getParam('website', 0)
                ),
                // The address the account was created under. The config field is
                // hidden, because a merchant never types it and editing it breaks
                // panel login - but they should still be able to see which account
                // this store is attached to.
                'account_email' => (string)$this->settings->accountEmail(
                    (int)$this->getRequest()->getParam('website', 0)
                ),
                'status' => $this->searchStatus((int)$this->getRequest()->getParam('website', 0)),
                // Seconds of settling period still to run after a connect. Above
                // zero, the template shows the setup progress bar in place of the
                // "connected" line - including on a reload part-way through.
                'hold_remaining' => $this->hold->remainingSeconds(
                    (int)$this->getRequest()->getParam('website', 0)
                ),
                'hold_seconds' => ConnectHold::HOLD_SECONDS,
            ])
            ->toHtml();
    }
}
