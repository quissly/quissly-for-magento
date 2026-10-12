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
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Search\MostLoved;

/**
 * The Most loved lists (shown, backups): the SKU box, and under it the products the SKUs are -
 * each with why it cannot be shown, if it cannot - and, under the backups, what still needs
 * fixing (MostLoved::status()), as the Quissly Shopify app's banner says it.
 */
class MostLovedField extends Field
{
    /**
     * @param Context $context
     * @param MostLoved $mostLoved
     * @param Settings $settings
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly MostLoved $mostLoved,
        private readonly Settings $settings,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $backups = (string)($element->getFieldConfig()['id'] ?? '') === 'backups';
        $websiteId = $this->websiteId();
        $storeId = $this->storeId($websiteId);
        $skus = $this->mostLoved->skus(
            $this->settings->mostLovedSkus($websiteId, $backups),
            $backups ? MostLoved::BACKUPS : MostLoved::SHOWN
        );
        $reasons = [
            'out_of_stock' => (string)__('out of stock'),
            'not_visible' => (string)__('not visible in the store'),
            'not_found' => (string)__('no product has this SKU'),
        ];
        $html = parent::_getElementHtml($element);
        if ($skus !== []) {
            $html .= '<ol class="q-most-loved__list">';
            foreach ($skus as $sku) {
                $state = $this->mostLoved->state($sku, $websiteId, $storeId);
                $html .= '<li>' . $this->escapeHtml($state['title'])
                    . ($state['title'] !== $sku
                        ? ' <span class="q-most-loved__sku">' . $this->escapeHtml($sku) . '</span>'
                        : '')
                    . ($state['reason'] !== '' ? ' <span class="q-most-loved__problem">- '
                        . $this->escapeHtml($reasons[$state['reason']] ?? $state['reason']) . '</span>' : '')
                    . '</li>';
            }
            $html .= '</ol>';
        }
        return $backups ? $html . $this->statusHtml($websiteId, $storeId) : $html;
    }

    /**
     * What still needs fixing, or ''.
     *
     * @param int|null $websiteId
     * @param int $storeId
     * @return string
     */
    private function statusHtml(?int $websiteId, int $storeId): string
    {
        $status = $this->mostLoved->status($websiteId, $storeId);
        if ($status === null) {
            return '';
        }
        $items = [];
        if ($status['missing_shown'] > 0) {
            $items[] = (string)__('Pick %1 more product(s) to show.', $status['missing_shown']);
        }
        if ($status['missing_backups'] > 0) {
            $items[] = (string)__('Pick %1 more backup(s).', $status['missing_backups']);
        }
        if ($status['problems'] !== []) {
            $items[] = (string)__('Some picks cannot be shown - see the lists above.');
        }
        $html = '<div class="message message-warning q-most-loved__status"><strong>'
            . $this->escapeHtml(__('Most loved products need attention')) . '</strong>';
        if ($status['hidden']) {
            $html .= '<p>' . $this->escapeHtml(__(
                'Shoppers don\'t see Most loved in search until 8 + 4 different products are picked '
                . 'and 8 of them are in stock.'
            )) . '</p>';
        }
        if ($status['duplicates'] !== []) {
            $html .= '<p>' . $this->escapeHtml(__(
                'These products are in both Shown and Backup products. Keep each in one list only:'
            )) . '</p><ul>';
            foreach ($status['duplicates'] as $title) {
                $html .= '<li>' . $this->escapeHtml($title) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<ul>';
        foreach ($items as $item) {
            $html .= '<li>' . $this->escapeHtml($item) . '</li>';
        }
        return $html . '</ul></div>';
    }

    /**
     * The website in scope; at default scope, the default store's website.
     *
     * @return int|null
     */
    private function websiteId(): ?int
    {
        $scoped = (int)$this->getRequest()->getParam('website', 0);
        if ($scoped > 0) {
            return $scoped;
        }
        try {
            return (int)$this->_storeManager->getDefaultStoreView()->getWebsiteId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The website's default store view (the names and stock shown here are that store view's).
     *
     * @param int|null $websiteId
     * @return int
     */
    private function storeId(?int $websiteId): int
    {
        try {
            $website = $this->_storeManager->getWebsite($websiteId ?? true);
            return (int)$website->getDefaultStore()->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
