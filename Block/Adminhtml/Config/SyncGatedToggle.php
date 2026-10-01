<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Block\Adminhtml\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Quissly\Search\Model\Sync\SyncCompletion;

/**
 * Shows a feature as unavailable until the first catalog sync has completed.
 *
 * RequiresFirstSync already refuses the save, but a control that looks usable
 * and then errors is a worse experience than one that says why up front - and
 * this module's own rule is not to render controls that cannot work.
 *
 * Disabled ONLY while the feature is off. A feature already running keeps a
 * working control, because the merchant must always be able to switch it off,
 * and because disabling it would stop the value being submitted at all.
 */
class SyncGatedToggle extends Field
{
    /**
     * @param Context $context
     * @param SyncCompletion $completion
     * @param ScopeConfigInterface $scopeConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly SyncCompletion $completion,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $websiteId = $this->resolvedWebsiteId();

        if ($this->completion->isComplete($websiteId)) {
            return parent::_getElementHtml($element);
        }

        // A setting that is already ON is a special case. AI Search ships ON by
        // default, so before the first sync the merchant would otherwise see a
        // plain "Yes" with nothing to say that the runtime gate is still holding
        // interception back - a control that reads live and does nothing. Say so,
        // but leave the control ENABLED: disabling it would trap a feature that
        // is legitimately on, and turning things off must always stay possible.
        $alreadyOn = $this->isAlreadyOn($element, $websiteId);
        $message = $alreadyOn ? $this->pendingMessage($websiteId) : $this->waitMessage($websiteId);

        // title= gives the native hover tooltip on both the control and the
        // note; disabled stops the click that would only fail on save.
        if (!$alreadyOn) {
            $element->setDisabled(true);
        }
        $element->setTitle($message);

        // "Quissly Dashboard" is where the merchant has to go next, so make it
        // the link rather than a place name they have to hunt for in the menu.
        // The message is escaped first and the anchor spliced into the escaped
        // string, so nothing user-supplied can reach the markup.
        $label = (string)__('Quissly Dashboard');
        $link = '<a href="' . $this->escapeHtmlAttr($this->getUrl('quissly/dashboard/index')) . '">'
            . $this->escapeHtml($label) . '</a>';
        $body = str_replace($this->escapeHtml($label), $link, $this->escapeHtml($message));

        return parent::_getElementHtml($element)
            . '<p class="note quissly-sync-pending" title="' . $this->escapeHtmlAttr($message) . '">'
            . '<span>' . $body . '</span></p>';
    }

    /**
     * The website whose gate answers for the scope being edited.
     *
     * Default scope is not a website, and website 0 has no gate - checking it
     * would disable the control forever on every store.
     *
     * @return int
     */
    private function resolvedWebsiteId(): int
    {
        $scoped = (int)$this->getRequest()->getParam('website', 0);
        if ($scoped > 0) {
            return $scoped;
        }

        try {
            return (int)$this->_storeManager->getDefaultStoreView()->getWebsiteId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Whether the feature is already switched on for this scope.
     *
     * Read from config, not from the element. The element's value is not the
     * stored setting at render time, and trusting it disabled the control for
     * a feature that was ON - leaving a merchant unable to switch it OFF,
     * which is the one thing this guard must never do.
     *
     * @param AbstractElement $element
     * @param int $websiteId
     * @return bool
     */
    private function isAlreadyOn(AbstractElement $element, int $websiteId): bool
    {
        $path = $this->configPath($element);
        if ($path === null) {
            // Without a path we cannot tell; leaving the control alone is the
            // safe direction, since the backend model still refuses the save.
            return true;
        }

        return $this->scopeConfig->isSetFlag(
            $path,
            $websiteId > 0 ? ScopeInterface::SCOPE_WEBSITE : ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $websiteId > 0 ? $websiteId : null
        );
    }

    /**
     * The config path this element edits.
     *
     * Config form elements carry no `path` of their own; the field NAME
     * encodes group and field, and the section is on the request.
     * Reconstructing it that way is deterministic, where deriving it from
     * html_id is not - field ids contain underscores, so section_group_field
     * cannot be split reliably.
     *
     * @param AbstractElement $element
     * @return string|null
     */
    private function configPath(AbstractElement $element): ?string
    {
        $section = (string)$this->getRequest()->getParam('section');
        $name = (string)$element->getData('name');

        if ($section === '' || !preg_match('/groups\[([^\]]+)\]\[fields\]\[([^\]]+)\]/', $name, $m)) {
            return null;
        }

        return $section . '/' . $m[1] . '/' . $m[2];
    }

    /**
     * What the merchant is waiting for, with progress when there is any.
     *
     * @param int $websiteId
     * @return string
     */
    private function pendingMessage(int $websiteId): string
    {
        $progress = $this->completion->progressSummary($websiteId);

        if ($progress['total'] > 0) {
            return (string)__(
                'Waiting for the first catalog sync to finish before this can be switched '
                . 'on - %1 of %2 sent, %3 still queued. Watch it on the Quissly Dashboard.',
                $progress['ok'],
                $progress['total'],
                $progress['pending']
            );
        }

        return (string)__(
            'Run the first catalog sync from the Quissly Dashboard before switching this on.'
        );
    }

    /**
     * Why a not-yet-enabled setting cannot be switched on.
     *
     * @param int $websiteId
     * @return string
     */
    private function waitMessage(int $websiteId): string
    {
        $progress = $this->completion->progressSummary($websiteId);
        $total = $progress['total'];
        $ok = $progress['ok'];
        $pending = $progress['pending'];

        if ($total > 0) {
            return (string)__(
                'Wait for the first catalog sync to finish - %1 of %2 sent, %3 still '
                . 'queued. Watch it on the Quissly Dashboard.',
                $ok,
                $total,
                $pending
            );
        }

        return (string)__('Run the first catalog sync from the Quissly Dashboard before switching this on.');
    }
}
