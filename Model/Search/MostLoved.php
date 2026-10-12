<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Review\Model\ReviewFactory;
use Quissly\Search\Model\Config\Settings;

/**
 * Most loved products: the merchant's picks, shown in the search overlay when it opens (the
 * Quissly Shopify app's "Most loved products"; the WooCommerce and CS-Cart plugins have the same).
 *
 * The merchant picks 8 products to show and 4 backups, by SKU, per website (Stores >
 * Configuration > Quissly > Most Loved Products). The overlay shows 8, in order: the shown ones
 * that can be bought, each one that cannot replaced by the first available backup; a product
 * picked twice shows once. With fewer than 8 + 4 picked, or 8 in stock between them, nothing is
 * shown. Configuration says what needs fixing (status()). Products are read in the shopper's
 * store view (its language, prices and links) from the repository, whose stock answer is right
 * in any area. The cards are part of the cached page, like the suggestion buttons.
 *
 * The rules themselves - which 8, what to warn about - are MostLovedPicks (pure, unit-tested).
 */
class MostLoved
{
    /** Products shown, and backups for the ones that cannot be bought. */
    public const SHOWN = MostLovedPicks::SHOWN;
    public const BACKUPS = MostLovedPicks::BACKUPS;

    /**
     * @param Settings $settings
     * @param ProductRepositoryInterface $products
     * @param ImageHelper $images
     * @param PriceCurrencyInterface $prices
     * @param ReviewFactory $reviews
     * @param MostLovedPicks $picks
     * @param CollectionFactory $collections
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ProductRepositoryInterface $products,
        private readonly ImageHelper $images,
        private readonly PriceCurrencyInterface $prices,
        private readonly ReviewFactory $reviews,
        private readonly MostLovedPicks $picks,
        private readonly CollectionFactory $collections
    ) {
    }

    /**
     * Products for Configuration's picker, a page at a time.
     *
     * Name or SKU containing the query - under 2 characters, the whole catalog (the picker opens
     * on it, as the Shopify app's) - and visible on their own.
     * The website's products only, named in its default store view, by name, $limit a page, each
     * with why it cannot be shown ('' when it can), and whether a next page exists.
     *
     * @param string $query
     * @param int|null $websiteId
     * @param int $storeId
     * @param int $page from 1
     * @param int $limit
     * @return array{items: array[], more: bool} items: sku, name, image, reason
     */
    public function search(string $query, ?int $websiteId, int $storeId, int $page = 1, int $limit = 20): array
    {
        $query = trim($query);
        $collection = $this->collections->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'thumbnail'])
            ->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE])
            ->setOrder('name', 'ASC')
            ->setPageSize($limit)
            ->setCurPage(max(1, $page));
        if (mb_strlen($query) >= 2) {
            $like = '%' . strtr($query, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            $collection->addAttributeToFilter(
                [['attribute' => 'name', 'like' => $like], ['attribute' => 'sku', 'like' => $like]]
            );
        }
        if ($websiteId !== null) {
            $collection->addWebsiteFilter([$websiteId]);
        }
        // Magento answers a page past the last with the last one again: none, then.
        $last = (int)$collection->getLastPageNumber();
        if ($page > $last) {
            return ['items' => [], 'more' => false];
        }
        $out = [];
        foreach ($collection as $product) {
            $out[] = $this->item((string)$product->getSku(), $websiteId, $storeId);
        }
        return ['items' => $out, 'more' => $page < $last];
    }

    /**
     * A pick as the picker shows it: SKU, name, thumbnail and why it cannot be shown ('' when it can).
     *
     * @param string $sku
     * @param int|null $websiteId
     * @param int $storeId
     * @return array{sku:string, name:string, image:string, reason:string}
     */
    public function item(string $sku, ?int $websiteId, int $storeId): array
    {
        $product = $this->load($sku, $storeId);
        if ($product === null) {
            return ['sku' => $sku, 'name' => $sku, 'image' => '', 'reason' => 'not_found'];
        }
        try {
            $image = (string)$this->images->init($product, 'product_thumbnail_image')->getUrl();
        } catch (\Throwable $e) {
            $image = '';
        }
        return [
            'sku' => $sku,
            'name' => (string)$product->getName(),
            'image' => $image,
            'reason' => $this->reason($product, $websiteId),
        ];
    }

    /**
     * SKUs as typed (MostLovedPicks::skus()).
     *
     * @param string $raw
     * @param int $max
     * @return string[]
     */
    public function skus(string $raw, int $max): array
    {
        return $this->picks->skus($raw, $max);
    }

    /**
     * The cards the overlay shows in a store view (none while it is off or short of picks).
     *
     * @param int|null $websiteId
     * @param int $storeId
     * @return array[] cards: title, url, image, price, rating, ratingCount, variants
     */
    public function forStorefront(?int $websiteId, int $storeId): array
    {
        if (!$this->settings->isMostLovedEnabled($websiteId)) {
            return [];
        }
        $loaded = [];
        $skus = $this->picks->pick(
            $this->skus($this->settings->mostLovedSkus($websiteId), self::SHOWN),
            $this->skus($this->settings->mostLovedSkus($websiteId, true), self::BACKUPS),
            function (string $sku) use ($websiteId, $storeId, &$loaded): bool {
                $loaded[$sku] = $this->load($sku, $storeId);
                return $loaded[$sku] !== null && $this->reason($loaded[$sku], $websiteId) === '';
            }
        );
        $cards = [];
        foreach ($skus as $sku) {
            $cards[] = $this->card($loaded[$sku], $storeId);
        }
        return $cards;
    }

    /**
     * What Configuration warns about for a website (problems()), or null - and null while off.
     *
     * @param int|null $websiteId
     * @param int $storeId the website's default store view
     * @return array|null
     */
    public function status(?int $websiteId, int $storeId): ?array
    {
        if (!$this->settings->isMostLovedEnabled($websiteId)) {
            return null;
        }
        return $this->picks->problems(
            $this->skus($this->settings->mostLovedSkus($websiteId), self::SHOWN),
            $this->skus($this->settings->mostLovedSkus($websiteId, true), self::BACKUPS),
            fn (string $sku): array => $this->state($sku, $websiteId, $storeId)
        );
    }

    /**
     * A picked SKU's name, and why it cannot be shown ('' when it can).
     *
     * @param string $sku
     * @param int|null $websiteId
     * @param int $storeId
     * @return array{title:string, reason:string}
     */
    public function state(string $sku, ?int $websiteId, int $storeId): array
    {
        $product = $this->load($sku, $storeId);
        if ($product === null) {
            return ['title' => $sku, 'reason' => 'not_found'];
        }
        return ['title' => (string)$product->getName(), 'reason' => $this->reason($product, $websiteId)];
    }

    /**
     * Why a product cannot be shown on a website, or ''.
     *
     * Reasons: not_visible (disabled, not in the website, or not visible on its own), out_of_stock.
     *
     * @param Product $product
     * @param int|null $websiteId
     * @return string
     */
    private function reason(Product $product, ?int $websiteId): string
    {
        if ((int)$product->getStatus() !== Status::STATUS_ENABLED
            || (int)$product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE
            || ($websiteId !== null
                && !in_array($websiteId, array_map('intval', (array)$product->getWebsiteIds()), true))
        ) {
            return 'not_visible';
        }
        return $product->isSalable() ? '' : 'out_of_stock';
    }

    /**
     * A product by SKU in a store view, or null.
     *
     * @param string $sku
     * @param int $storeId
     * @return Product|null
     */
    private function load(string $sku, int $storeId): ?Product
    {
        try {
            $product = $this->products->get($sku, false, $storeId);
        } catch (\Throwable $e) {
            return null;
        }
        return $product instanceof Product ? $product : null;
    }

    /**
     * A card for the overlay.
     *
     * Name, link, image, price (a configurable product's lowest), its reviews, and how many of
     * its first option there are ("3 colors available").
     *
     * @param Product $product
     * @param int $storeId
     * @return array title, url, image, price, rating, ratingCount, variants
     */
    private function card(Product $product, int $storeId): array
    {
        $price = (float)$product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
        $rating = 0.0;
        $count = 0;
        try {
            $this->reviews->create()->getEntitySummary($product, $storeId);
            $summary = $product->getRatingSummary();
            if (is_object($summary)) {
                $rating = round((float)$summary->getRatingSummary() / 20, 1);
                $count = (int)$summary->getReviewsCount();
            }
        } catch (\Throwable $e) {
            unset($e); // no reviews module: no stars
        }
        $variants = '';
        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $type = $product->getTypeInstance();
            $children = count($type->getUsedProducts($product));
            $attribute = $type->getConfigurableAttributes($product)->getFirstItem();
            $label = is_object($attribute) ? trim((string)$attribute->getLabel()) : '';
            if ($children > 1) {
                $variants = $label !== ''
                    ? (string)__('%1 %2 available', $children, mb_strtolower($label) . 's')
                    : (string)__('%1 options available', $children);
            }
        }
        $formatted = (string)$this->prices->format($price, false, PriceCurrencyInterface::DEFAULT_PRECISION, $storeId);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- plain text for a JSON card, not rendering
        $formatted = trim(html_entity_decode(strip_tags($formatted), ENT_QUOTES, 'UTF-8'));
        return [
            'title' => (string)$product->getName(),
            'url' => (string)$product->getProductUrl(),
            'image' => (string)$this->images->init($product, 'product_thumbnail_image')->getUrl(),
            'price' => $formatted,
            'rating' => $rating,
            'ratingCount' => $count,
            'variants' => $variants,
        ];
    }
}
