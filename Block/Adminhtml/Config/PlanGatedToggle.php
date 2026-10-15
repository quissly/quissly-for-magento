<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Block\Adminhtml\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Quissly\Search\Model\Api\StoreBilling;
use Quissly\Search\Model\Connect\PlanGate;

/**
 * Shows a feature the website's plan leaves out (Model\Connect\PlanGate) as off
 * and disabled, with the reason and a link to Billing - this module's rule is
 * not to render controls that cannot work. A disabled field is not submitted,
 * so what was saved stays for when the plan includes the feature again; until
 * then Plugin\Config\PlanLock keeps it off.
 *
 * Quissly is asked about the plan when the page is drawn, so a plan changed in
 * Billing shows at once.
 */
class PlanGatedToggle extends Field
{
    /**
     * @param Context $context
     * @param PlanGate $planGate
     * @param StoreBilling $billing
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly PlanGate $planGate,
        private readonly StoreBilling $billing,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->planLockedHtml($element) ?? parent::_getElementHtml($element);
    }

    /**
     * The disabled control and why, or null when the plan includes the feature.
     *
     * @param AbstractElement $element
     * @return string|null
     */
    protected function planLockedHtml(AbstractElement $element): ?string
    {
        $path = $this->configPath($element);
        $websiteId = $this->resolvedWebsiteId();
        if ($path === null || $websiteId <= 0) {
            return null;
        }
        $this->planGate->refresh($websiteId, function () use ($websiteId): ?array {
            return $this->billing->subscriptions($websiteId);
        }, true, time());
        $product = $this->planGate->locked($websiteId)[$path] ?? null;
        if ($product === null) {
            return null;
        }
        $message = $product === 'qchat'
            ? (string)__("Your plan doesn't include the AI shopping assistant.")
            : (string)__("Your plan doesn't include Search.");
        $element->setDisabled(true);
        $element->setValue('0');
        $element->setTitle($message);

        return parent::_getElementHtml($element)
            . '<p class="note quissly-plan-locked"><span>' . $this->escapeHtml($message) . ' '
            . '<a href="' . $this->escapeHtmlAttr($this->getUrl('quissly/billing/index')) . '">'
            . $this->escapeHtml(__('Add it in Billing')) . '</a></span></p>';
    }

    /**
     * The website whose plan and gate answer for the scope being edited.
     *
     * Default scope is not a website, and website 0 has no gate - checking it
     * would disable the control forever on every store.
     *
     * @return int
     */
    protected function resolvedWebsiteId(): int
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
     * The config path this element edits.
     *
     * A field with its own <config_path> says so in its field config; otherwise
     * the field NAME encodes group and field, and the section is on the request.
     * Reconstructing it that way is deterministic, where deriving it from
     * html_id is not - field ids contain underscores, so section_group_field
     * cannot be split reliably.
     *
     * @param AbstractElement $element
     * @return string|null
     */
    protected function configPath(AbstractElement $element): ?string
    {
        $config = $element->getData('field_config');
        if (is_array($config) && !empty($config['config_path'])) {
            return (string)$config['config_path'];
        }
        $section = (string)$this->getRequest()->getParam('section');
        $name = (string)$element->getData('name');

        if ($section === '' || !preg_match('/groups\[([^\]]+)\]\[fields\]\[([^\]]+)\]/', $name, $m)) {
            return null;
        }

        return $section . '/' . $m[1] . '/' . $m[2];
    }
}
