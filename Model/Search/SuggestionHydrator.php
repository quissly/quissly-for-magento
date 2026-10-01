<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * Turns Quick's ordered product ids into something renderable, from Magento.
 *
 * Quick returns ids and scores; everything a dropdown shows - name, image,
 * price, link - is loaded here. That is not a workaround for a thin response,
 * it is the right source: this data comes from the shopper's own store view,
 * so names are in their language, prices in their currency with catalog rules
 * applied, images at the theme's configured sizes, and links from url_rewrite
 * rather than a copy written at the last catalog sync.
 *
 * Two rules the dropdown depends on:
 *
 * ORDER. Quick's ranking is the only thing that endpoint decides. Magento
 * returns collections in its own order, so the results are re-keyed back into
 * the order the ids arrived in.
 *
 * ELIGIBILITY. A product disabled or hidden from search since the last sync is
 * dropped rather than shown - Quissly's index lags Magento, and offering a
 * shopper something they cannot buy is worse than offering nothing.
 */
class SuggestionHydrator
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param ImageHelper $imageHelper
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ImageHelper $imageHelper,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * Load the products Quick chose, in the order it chose them.
     *
     * @param array $ids Ordered product ids from Quick
     * @return array
     */
    public function hydrate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->addIdFilter($ids)
            // 'thumbnail' is what product_thumbnail_image renders (view.xml
            // declares it type="thumbnail"). Leave it out of the select and
            // the helper finds no value on the loaded product and falls back to
            // the theme placeholder - a dropdown of grey squares for products
            // that have perfectly good images.
            ->addAttributeToSelect(
                ['name', 'image', 'small_image', 'thumbnail', 'price', 'special_price', 'url_key']
            )
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter(
                'visibility',
                ['in' => [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]]
            )
            ->addUrlRewrite()
            ->addPriceData();

        $byId = [];
        foreach ($collection as $product) {
            $byId[(int)$product->getId()] = $this->suggestion($product);
        }

        // Back into Quick's order - Magento's is its own.
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * One renderable suggestion, from Magento's own data.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return array
     */
    private function suggestion($product): array
    {
        $regular = (float)$product->getPrice();
        $final = (float)($product->getFinalPrice() ?: $regular);

        return [
            'id' => (string)(int)$product->getId(),
            'title' => (string)$product->getName(),
            'url' => (string)$product->getProductUrl(),
            'image' => $this->image($product),
            'price' => $this->priceCurrency->round($final > 0.0 ? $final : $regular),
            // Only a genuine discount; equal prices would render a pointless
            // strikethrough next to itself.
            'was' => $regular > $final && $final > 0.0 ? $this->priceCurrency->round($regular) : null,
        ];
    }

    /**
     * A thumbnail at the theme's configured size, from Magento's image cache.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    private function image($product): string
    {
        try {
            return (string)$this->imageHelper
                ->init($product, 'product_thumbnail_image')
                ->getUrl();
        } catch (\Throwable $e) {
            // A product with no image is normal; the widget renders a
            // placeholder rather than a broken tile.
            return '';
        }
    }
}
