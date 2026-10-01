<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\ViewModel;

use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Quissly\Search\Model\Config\Settings;

/**
 * What the Quick widget needs to bootstrap, and whether to bootstrap at all.
 */
class QuickConfig implements ArgumentInterface
{
    /**
     * @param Settings $settings
     * @param UrlInterface $url
     * @param StoreManagerInterface $storeManager
     * @param CurrencyFactory $currencyFactory
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $storeManager,
        private readonly CurrencyFactory $currencyFactory
    ) {
    }

    /**
     * Emit nothing at all when the feature is off.
     *
     * Not "render hidden": a disabled store should ship no markup, no script
     * and no endpoint reference for this feature.
     *
     * @return bool
     */
    public function shouldRender(): bool
    {
        return $this->settings->isQuickEnabled();
    }

    /**
     * Which presentation the merchant chose for this website.
     *
     * @return string QuickStyle::ROWS|QuickStyle::CAROUSEL
     */
    public function style(): string
    {
        return $this->settings->quickStyle();
    }

    /**
     * The storefront proxy this widget calls.
     *
     * @return string
     */
    public function endpoint(): string
    {
        return $this->url->getUrl('quissly/quick/suggest');
    }

    /**
     * Where "see all" goes: the ordinary results page for the typed term.
     *
     * The widget appends the query, so this stays a plain base URL and the
     * shopper lands exactly where pressing Enter would have taken them.
     *
     * @return string
     */
    public function resultsUrl(): string
    {
        return $this->url->getUrl('catalogsearch/result');
    }

    /**
     * The store's currency symbol, for prices the widget renders itself.
     *
     * Quick returns bare numbers, and a price with no currency beside it is
     * ambiguous on any store that is not in dollars.
     *
     * @return string
     */
    public function currencySymbol(): string
    {
        $code = (string)$this->storeManager->getStore()->getCurrentCurrencyCode();

        return (string)$this->currencyFactory->create()->load($code)->getCurrencySymbol();
    }
}
