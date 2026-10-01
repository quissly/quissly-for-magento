<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Magento product → Quissly ProductItem record (R3-verified 2026-08-17;
 * catalog data decisions 2026-09-10).
 *
 * Rules baked in:
 *  - ids are STRINGS on the wire (integer ids are silently dropped by ingest);
 *  - description is REQUIRED (falls back name), original_price required number;
 *  - discounted_price = EFFECTIVE FINAL PRICE ALWAYS (price-sort exclusion trap);
 *  - configurable → parent record + variants[] of FULL ProductItems (no server
 *    parent-fallback), selected_options on each leaf (drives facets), each
 *    variant's url = the parent page opened on that variant (#attr=option);
 *  - selection rule: sync what is VISIBLE - configurables + standalone
 *    visible simples; children ride inside variants[] only;
 *  - bundle → ONE record, no variants: the indexed minimum price as the exact
 *    price, option groups and their selections in the text and in
 *    metadata.bundle_options as option groups with their items, `type` and
 *    `price_type` beside them, and the parts' main images after the bundle's
 *    own (2026-09-10). Grouped: still out of scope;
 *  - up to MAX_IMAGES gallery images, main first, for every type;
 *  - `category` = the DEEPEST assigned category (Magento's collection order is
 *    creation order, which means nothing to a shopper); the full list stays in
 *    metadata.categories on the parent - variants inherit it server-side;
 *  - the merchant's attribute list rides in metadata (by code) AND inside the
 *    embedded text, on parents in full and on variants only where a value
 *    differs from the parent's;
 *  - native review summary as metadata.rating / reviews_count when present.
 */
class ProductMapper
{
    public const SYNCABLE_TYPES = ['simple', 'virtual', 'downloadable', 'configurable', 'bundle'];

    /** Gallery images per record, main image first (2026-09-10). */
    public const MAX_IMAGES = 10;

    /** The description proper; attribute and option text is appended within MAX_EXTRA_TEXT. */
    public const MAX_DESCRIPTION = 2000;
    public const MAX_EXTRA_TEXT = 1500;

    private const TYPE_BUNDLE = 'bundle';

    /** Magento type id -> the platform-neutral type every plugin sends (2026-09-10). */
    private const WIRE_TYPES = [
        'simple' => 'simple',
        'configurable' => 'variable',
        'bundle' => 'bundle',
        'virtual' => 'virtual',
        'downloadable' => 'downloadable',
        'grouped' => 'grouped',
    ];

    /**
     * @param CatalogAttributes $attributes
     * @param ReviewSummary $reviews
     * @param GalleryReadHandler $galleryReader
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(
        private readonly CatalogAttributes $attributes,
        private readonly ReviewSummary $reviews,
        private readonly GalleryReadHandler $galleryReader,
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    /**
     * One selected option in the LOCKED wire shape: a {"name","value"} object.
     *
     * The deployed search's facet builder iterates selected_options expecting
     * exactly these keys and 500-crashes every matching query on any other
     * shape (backend trim.py:149; confirmed by the Quissly team 2026-08-18).
     * Pure by design so the
     * unit tier locks the shape without Magento.
     *
     * @param string $code attribute code (fallback name)
     * @param string $label store label (preferred name, lowercased)
     * @param string $value the child's display value ('' = option omitted)
     * @return array|null
     */
    public function selectedOption(string $code, string $label, string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        return ['name' => $label !== '' ? strtolower($label) : $code, 'value' => $value];
    }

    /**
     * Whether a product belongs in the index for a website.
     *
     * @param Product $product
     * @param int $websiteId
     * @return bool
     */
    public function isEligible(Product $product, int $websiteId): bool
    {
        return in_array($product->getTypeId(), self::SYNCABLE_TYPES, true)
            && (int)$product->getStatus() === Status::STATUS_ENABLED
            && in_array(
                (int)$product->getVisibility(),
                [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH],
                true
            )
            && in_array($websiteId, array_map('intval', (array)$product->getWebsiteIds()), true);
    }

    /**
     * Map an eligible product to its wire record.
     *
     * @param Product $product
     * @param StoreInterface $store
     * @param int $websiteId Scope for the merchant's attribute list (0 = default)
     * @return array
     */
    public function map(Product $product, StoreInterface $store, int $websiteId = 0): array
    {
        $id = (string)(int)$product->getId();
        $parentAttributes = $this->attributes->values($product, $websiteId ?: null);
        $record = $this->baseRecord($product, $store, $parentAttributes);
        $variantMap = [$id => $id];

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            [$variants, $map, $priceRange] = $this->mapVariants($product, $store, $websiteId, $parentAttributes);
            if ($variants !== []) {
                $record['variants'] = $variants;
                $record['metadata']['variation_count'] = count($variants);
                // Parent headline prices = the cheapest variant (the Woo rule).
                $record['original_price'] = $priceRange['original'];
                $record['discounted_price'] = $priceRange['discounted'];
                // Parent in-stock = any variant in stock.
                $record['in_stock'] = $priceRange['any_in_stock'];
                $variantMap += $map;
            }
        }

        // Since 2026-08-18 the backend indexes childless records on the fast
        // path, so simples ship as clean childless records: "variants": [] is
        // the live-verified childless shape. The variant map stays: real
        // configurables need their per-variant receipts rolled up.
        if (!isset($record['variants'])) {
            $record['variants'] = [];
        }

        return ['record' => $record, 'variant_map' => $variantMap];
    }

    /**
     * The common ProductItem fields.
     *
     * @param Product $product
     * @param StoreInterface $store
     * @param array $attributes
     * @return array
     */
    private function baseRecord(Product $product, StoreInterface $store, array $attributes): array
    {
        $isBundle = $product->getTypeId() === self::TYPE_BUNDLE;
        if ($isBundle) {
            [$regular, $final] = $this->bundlePrices(
                (float)$product->getData('price'),
                (float)$product->getData('min_price'),
                (float)$product->getPrice()
            );
        } else {
            $regular = (float)$product->getPrice();
            $final = (float)($product->getFinalPrice() ?: $regular);
            if ($regular <= 0.0) {
                $regular = $final; // original_price must be a number; 0.0 only when truly priceless
            }
        }
        $categories = $this->categories($product);
        $options = $isBundle ? $this->bundleOptions($product, $store) : [];
        $review = $this->reviews->summary($product, (int)$store->getId());
        $fixedPrice = $isBundle && (int)$product->getData('price_type') === 1;

        return [
            'id' => (string)(int)$product->getId(),
            'title' => (string)$product->getName(),
            'description' => $this->composeText($this->description($product), $attributes, $options),
            'category' => $this->deepestCategory($categories),
            'original_price' => round($regular, 2),
            // EFFECTIVE final price ALWAYS - never null (price-sort exclusion).
            'discounted_price' => round($final > 0.0 ? $final : $regular, 2),
            'in_stock' => $this->isInStock($product, (int)$store->getWebsiteId()),
            'images' => $isBundle
                ? $this->appendImages($this->images($product, $store), $this->optionImages($options), self::MAX_IMAGES)
                : $this->images($product, $store),
            'url' => $this->productUrl($product, $store),
            'metadata' => $this->guardMetadata($this->withoutEmpty(
                [
                    'sku' => (string)$product->getSku(),
                    'type' => $this->wireType((string)$product->getTypeId()),
                    'categories' => $this->categoryList($categories),
                ]
                + $this->attributeMetadata($attributes)
                + ($isBundle ? ['price_type' => $fixedPrice ? 'fixed' : 'dynamic'] : [])
                + ($options !== [] ? ['bundle_options' => $options] : [])
                + ($review !== null ? ['rating' => $review['rating'], 'reviews_count' => $review['count']] : [])
            )),
        ];
    }

    /**
     * Configurable children as FULL ProductItems.
     *
     * Required fields are copied from the parent when a child lacks them -
     * there is no server-side inheritance (R3-verified 422 otherwise).
     *
     * @param Product $parent
     * @param StoreInterface $store
     * @param int $websiteId
     * @param array $parentAttributes
     * @return array
     */
    private function mapVariants(Product $parent, StoreInterface $store, int $websiteId, array $parentAttributes): array
    {
        $parentId = (string)(int)$parent->getId();
        /** @var Configurable $typeInstance */
        $typeInstance = $parent->getTypeInstance();
        $attributes = $typeInstance->getConfigurableAttributes($parent);
        $variants = [];
        $map = [];
        $minOriginal = null;
        $minDiscounted = null;
        $anyInStock = false;
        // Quissly flattens variants into records of their own and answers a
        // query with whichever variant matched, so a variant's flag must be
        // the truth about BUYING it. A parent switched off sells nothing in
        // Magento, whatever its variants hold - so its variants read out of
        // stock too, never "in stock under a parent that is not".
        $parentInStock = $this->isInStock($parent, (int)$store->getWebsiteId());
        $parentCategory = $this->deepestCategory($this->categories($parent));
        $parentImages = $this->images($parent, $store);

        foreach ($typeInstance->getUsedProducts($parent) as $child) {
            /** @var Product $child */
            $childId = (string)(int)$child->getId();
            $regular = (float)$child->getPrice();
            $final = (float)($child->getFinalPrice() ?: $regular);
            if ($regular <= 0.0) {
                $regular = $final;
            }
            $selectedOptions = [];
            $selection = [];
            foreach ($attributes as $attribute) {
                $code = $attribute->getProductAttribute()->getAttributeCode();
                $label = (string)$attribute->getProductAttribute()->getStoreLabel();
                $value = (string)$child->getAttributeText($code);
                $option = $this->selectedOption($code, $label, $value);
                if ($option !== null) {
                    $selectedOptions[] = $option;
                }
                $optionId = $child->getData($code);
                if ($optionId !== null && $optionId !== '') {
                    $selection[(int)$attribute->getProductAttribute()->getAttributeId()] = (int)$optionId;
                }
            }
            // Only what is the child's OWN: values identical to the parent's are
            // inherited server-side (2026-09-10).
            $own = $this->differingValues($parentAttributes, $this->attributes->values($child, $websiteId ?: null));
            $inStock = $parentInStock && $this->isInStock($child, (int)$store->getWebsiteId());
            $variants[] = [
                'id' => $childId,
                'title' => (string)($child->getName() ?: $parent->getName()),
                'description' => $this->composeText(
                    $this->description($child) ?: $this->description($parent),
                    $own,
                    []
                ),
                'category' => $parentCategory,
                'original_price' => round($regular, 2),
                'discounted_price' => round($final > 0.0 ? $final : $regular, 2),
                'in_stock' => $inStock,
                'images' => $this->images($child, $store) ?: $parentImages,
                // The parent page opened on THIS variant: children have no page of
                // their own, and a bare parent URL lands the shopper on the default
                // colour (2026-09-10).
                'url' => $this->variantUrl($this->productUrl($parent, $store), $selection, (int)$childId),
                'metadata' => $this->guardMetadata($this->withoutEmpty(
                    [
                        'sku' => (string)$child->getSku(),
                        'type' => 'simple',
                        'selected_options' => $selectedOptions,
                    ]
                    + $this->attributeMetadata($own)
                )),
            ];
            $map[$childId] = $parentId;
            $minOriginal = $minOriginal === null ? $regular : min($minOriginal, $regular);
            $minDiscounted = $minDiscounted === null ? ($final ?: $regular) : min($minDiscounted, $final ?: $regular);
            $anyInStock = $anyInStock || $inStock;
        }

        return [$variants, $map, [
            'original' => round((float)($minOriginal ?? $parent->getPrice()), 2),
            'discounted' => round((float)($minDiscounted ?? $minOriginal ?? $parent->getPrice()), 2),
            // Already folds the parent's own switch in: every variant flag does.
            'any_in_stock' => $anyInStock,
        ]];
    }

    /**
     * Bundle prices from the price index: the floor is the exact price.
     *
     * A bundle's own price attribute is 0 for dynamic-priced bundles, and the
     * backend has no "from" price - so the indexed minimum (the cheapest valid
     * configuration, specials applied) is sent as what the shopper pays, and
     * the index's undiscounted minimum as the original, so a "-30%" set still
     * reads as one.
     *
     * @param float $indexedPrice price_index.price (undiscounted minimum)
     * @param float $minPrice price_index.min_price (discounted minimum)
     * @param float $attributePrice the price attribute (fixed-price bundles)
     * @return array{0: float, 1: float} [original, discounted]
     */
    public function bundlePrices(float $indexedPrice, float $minPrice, float $attributePrice): array
    {
        $final = $minPrice > 0.0 ? $minPrice : ($attributePrice > 0.0 ? $attributePrice : $indexedPrice);
        $regular = max($indexedPrice, $final);
        return [$regular, $final];
    }

    /**
     * A bundle's option groups, each with the parts a shopper can pick.
     *
     * The shape agreed 2026-09-10 (metadata.bundle_options): `type` says it is a
     * bundle, `price_type`
     * whether the parts' prices add up or the set has one fixed price, and
     * each group carries `required`, `multiple` (checkbox / multiselect groups
     * take several) and `items` described in the record's own vocabulary -
     * id, sku, title, original_price, discounted_price, in_stock, qty,
     * default, url (only when the part is a product a shopper can open on
     * its own) and image.
     *
     * @param Product $product
     * @param StoreInterface $store
     * @return array
     */
    private function bundleOptions(Product $product, StoreInterface $store): array
    {
        $type = $product->getTypeInstance();
        if (!method_exists($type, 'getOptionsCollection')) {
            return [];
        }
        $fixed = (int)$product->getData('price_type') === 1;
        $bundlePrice = (float)$product->getPrice();
        $base = rtrim((string)$store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
        $groups = [];
        try {
            $optionIds = $type->getOptionsIds($product);
            $selections = $type->getSelectionsCollection($optionIds, $product)
                ->addAttributeToSelect(['visibility', 'url_key', 'image', 'small_image', 'price', 'special_price']);
            $byOption = [];
            foreach ($selections as $selection) {
                $visible = in_array((int)$selection->getVisibility(), [
                    Visibility::VISIBILITY_IN_CATALOG,
                    Visibility::VISIBILITY_IN_SEARCH,
                    Visibility::VISIBILITY_BOTH,
                ], true);
                $file = (string)($selection->getData('image') ?: $selection->getData('small_image'));
                [$original, $discounted] = $this->bundleItemPrices(
                    $fixed,
                    (int)$selection->getSelectionPriceType(),
                    (float)$selection->getSelectionPriceValue(),
                    $bundlePrice,
                    (float)$selection->getPrice(),
                    (float)$selection->getFinalPrice()
                );
                $byOption[(int)$selection->getOptionId()][] = [
                    'id' => (string)(int)$selection->getId(),
                    'sku' => (string)$selection->getSku(),
                    'title' => (string)$selection->getName(),
                    'original_price' => $original,
                    'discounted_price' => $discounted,
                    'in_stock' => $this->isInStock($selection, (int)$store->getWebsiteId()),
                    'qty' => (float)$selection->getSelectionQty(),
                    'default' => (bool)$selection->getIsDefault(),
                    'url' => $visible ? $this->productUrl($selection, $store) : null,
                    'image' => $file !== '' && $file !== 'no_selection' ? $base . '/catalog/product' . $file : null,
                ];
            }
            foreach ($type->getOptionsCollection($product) as $option) {
                $groups[] = [
                    'name' => (string)($option->getTitle() ?: $option->getDefaultTitle()),
                    'required' => (bool)$option->getRequired(),
                    'multiple' => in_array((string)$option->getType(), ['checkbox', 'multi'], true),
                    'items' => $byOption[(int)$option->getOptionId()] ?? [],
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $this->bundleOptionGroups($groups);
    }

    /**
     * What a part costs inside this bundle.
     *
     * A fixed-price bundle prices each selection itself, as an amount or as a
     * percentage of the bundle price; a dynamic one adds up the parts' own
     * prices, specials included. Either way discounted_price is what the
     * shopper pays for that part and is never null.
     *
     * @param bool $fixedBundle
     * @param int $selectionPriceType 0 = fixed amount, 1 = percent of the bundle price
     * @param float $selectionPriceValue
     * @param float $bundlePrice
     * @param float $partRegular
     * @param float $partFinal
     * @return array [original_price, discounted_price]
     */
    public function bundleItemPrices(
        bool $fixedBundle,
        int $selectionPriceType,
        float $selectionPriceValue,
        float $bundlePrice,
        float $partRegular,
        float $partFinal
    ): array {
        if ($fixedBundle) {
            $price = $selectionPriceType === 1 ? $bundlePrice * $selectionPriceValue / 100 : $selectionPriceValue;
            return [round($price, 2), round($price, 2)];
        }
        $regular = $partRegular > 0.0 ? $partRegular : $partFinal;
        $final = $partFinal > 0.0 ? $partFinal : $regular;
        return [round($regular, 2), round($final, 2)];
    }

    /**
     * Normalise bundle option groups for the wire.
     *
     * Lowercase names and text-cleaned titles, quantities as numbers, items
     * without a title and groups without items dropped.
     *
     * @param array $groups
     * @return array
     */
    public function bundleOptionGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $name = strtolower($this->plainText((string)($group['name'] ?? '')));
            $items = [];
            foreach ((array)($group['items'] ?? []) as $item) {
                $title = $this->plainText((string)($item['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $qty = (float)($item['qty'] ?? 1);
                $items[] = [
                    'id' => (string)($item['id'] ?? ''),
                    'sku' => (string)($item['sku'] ?? ''),
                    'title' => $title,
                    'original_price' => round((float)($item['original_price'] ?? 0), 2),
                    'discounted_price' => round((float)($item['discounted_price'] ?? 0), 2),
                    'in_stock' => (bool)($item['in_stock'] ?? true),
                    'qty' => $qty > 0 ? ($qty == floor($qty) ? (int)$qty : $qty) : 1,
                    'default' => (bool)($item['default'] ?? false),
                    'url' => isset($item['url']) && (string)$item['url'] !== '' ? (string)$item['url'] : null,
                    'image' => isset($item['image']) && (string)$item['image'] !== '' ? (string)$item['image'] : null,
                ];
            }
            if ($name === '' || $items === []) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'required' => (bool)($group['required'] ?? false),
                'multiple' => (bool)($group['multiple'] ?? false),
                'items' => $items,
            ];
        }
        return $out;
    }

    /**
     * The parts' images, in option order, for the bundle's image list.
     *
     * @param array $options normalised option groups
     * @return string[]
     */
    private function optionImages(array $options): array
    {
        $urls = [];
        foreach ($options as $group) {
            foreach ($group['items'] as $item) {
                if (!empty($item['image'])) {
                    $urls[] = $item['image'];
                }
            }
        }
        return $urls;
    }

    /**
     * The platform-neutral product type every plugin sends.
     *
     * @param string $magentoType
     * @return string
     */
    public function wireType(string $magentoType): string
    {
        return self::WIRE_TYPES[$magentoType] ?? $magentoType;
    }

    /**
     * First list, then whatever the second adds, unique, at most $max.
     *
     * @param string[] $urls
     * @param string[] $more
     * @param int $max
     * @return string[]
     */
    public function appendImages(array $urls, array $more, int $max): array
    {
        foreach ($more as $url) {
            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }
        return array_slice(array_values($urls), 0, max(0, $max));
    }

    /**
     * The text Quissly embeds.
     *
     * The description, then the attribute values and bundle options as
     * "Label: value." sentences, so a material or a size matches a query even
     * when the prose never mentions it.
     *
     * @param string $description
     * @param array $attributes
     * @param array $options
     * @return string
     */
    public function composeText(string $description, array $attributes, array $options): string
    {
        $lines = [];
        foreach ($attributes as $attribute) {
            $lines[] = $this->sentence($attribute['name'], (array)$attribute['value']);
        }
        foreach ($options as $group) {
            $lines[] = $this->sentence($group['name'], array_column((array)($group['items'] ?? []), 'title'));
        }
        $extra = mb_substr(implode(' ', array_filter($lines)), 0, self::MAX_EXTRA_TEXT);
        $text = trim($description);
        if ($extra !== '') {
            $text = $text !== '' ? $text . "\n" . $extra : $extra;
        }
        return $text;
    }

    /**
     * The metadata keys for the attribute values: code => value.
     *
     * @param array $attributes
     * @return array
     */
    public function attributeMetadata(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $code => $attribute) {
            $out[(string)$code] = $attribute['value'];
        }
        return $out;
    }

    /**
     * Top-level ProductItem field names on the /add wire (backend
     * catalog_models.py ProductItem, plus the id). A metadata key matching one
     * of these - compared case-insensitively - is renamed to attr_<key>.
     */
    public const RESERVED_METADATA_KEYS = [
        'id', 'title', 'description', 'category', 'images', 'url', 'in_stock',
        'original_price', 'discounted_price', 'discount_percent', 'variants', 'metadata',
    ];

    /**
     * Prefix any metadata key that collides with a top-level ProductItem field.
     *
     * The colliding key becomes attr_<key>. The backend cannot hold the same
     * attribute name inside and outside
     * metadata (2026-09-10). A merchant's custom attribute may carry
     * any code - "title" on a bookshop, say - so this runs as the LAST step on
     * every metadata dict, parents and variants alike. Matching is
     * case-insensitive and the key's own spelling is kept after the prefix;
     * every other key passes through untouched, in order. Pure - it reads no
     * state - so the unit tier locks it without Magento.
     *
     * @param array $metadata
     * @return array
     */
    public function guardMetadata(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $key => $value) {
            $name = (string)$key;
            if (in_array(strtolower($name), self::RESERVED_METADATA_KEYS, true)) {
                $name = 'attr_' . $name;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    /**
     * The child's attribute values that are its own, not the parent's.
     *
     * @param array $parent
     * @param array $child
     * @return array
     */
    public function differingValues(array $parent, array $child): array
    {
        $own = [];
        foreach ($child as $code => $entry) {
            if (!isset($parent[$code]) || $parent[$code]['value'] !== $entry['value']) {
                $own[$code] = $entry;
            }
        }
        return $own;
    }

    /**
     * The deepest assigned category name, or 'default'.
     *
     * @param array $categories
     * @return string
     */
    public function deepestCategory(array $categories): string
    {
        $best = null;
        foreach ($categories as $category) {
            if ($best === null || $category['level'] > $best['level']) {
                $best = $category;
            }
        }
        return $best['name'] ?? 'default';
    }

    /**
     * All assigned category names, in Magento's order, unique.
     *
     * @param array $categories
     * @return string[]
     */
    public function categoryList(array $categories): array
    {
        return array_values(array_unique(array_map(static fn (array $c): string => $c['name'], $categories)));
    }

    /**
     * Image URLs, at most $max.
     *
     * The main image first, then the gallery by position; disabled and
     * non-image entries skipped.
     *
     * @param string $main the `image` attribute value ('' / no_selection = none)
     * @param array $gallery
     * @param string $base media base URL, no trailing slash
     * @param int $max
     * @return string[]
     */
    public function imageUrls(string $main, array $gallery, string $base, int $max): array
    {
        $files = [];
        if ($main !== '' && $main !== 'no_selection') {
            $files[] = $main;
        }
        usort($gallery, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        foreach ($gallery as $entry) {
            $file = (string)($entry['file'] ?? '');
            if ($file === '' || $file === 'no_selection' || !empty($entry['disabled'])) {
                continue;
            }
            if (($entry['type'] ?? 'image') !== 'image') {
                continue;
            }
            if (!in_array($file, $files, true)) {
                $files[] = $file;
            }
        }
        return array_map(
            static fn (string $file): string => $base . '/catalog/product' . $file,
            array_slice($files, 0, max(0, $max))
        );
    }

    /**
     * Required description with fallbacks, tags stripped.
     *
     * @param Product $product
     * @return string
     */
    private function description(Product $product): string
    {
        $raw = (string)($product->getData('description')
            ?: $product->getData('short_description')
            ?: $product->getName());
        $text = $this->plainText($raw);
        return $text !== '' ? mb_substr($text, 0, self::MAX_DESCRIPTION) : (string)$product->getName();
    }

    /**
     * Product image URLs (up to MAX_IMAGES; the no-image marker skipped).
     *
     * @param Product $product
     * @param StoreInterface $store
     * @return string[]
     */
    private function images(Product $product, StoreInterface $store): array
    {
        $base = rtrim((string)$store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
        $gallery = [];
        try {
            if (!$product->hasData('media_gallery')) {
                // Children come off the configurable's type instance without
                // their galleries; one read per child fetches it.
                $this->galleryReader->execute($product);
            }
            $items = $product->getMediaGalleryImages();
            if ($items) {
                foreach ($items as $item) {
                    $gallery[] = [
                        'file' => (string)$item->getFile(),
                        'position' => (int)$item->getPosition(),
                        'disabled' => (bool)$item->getDisabled(),
                        'type' => (string)($item->getMediaType() ?: 'image'),
                    ];
                }
            }
        } catch (\Throwable $e) {
            $gallery = [];
        }
        return $this->imageUrls((string)$product->getData('image'), $gallery, $base, self::MAX_IMAGES);
    }

    /**
     * Canonical product URL for the store.
     *
     * @param Product $product
     * @param StoreInterface $store
     * @return string
     */
    private function productUrl(Product $product, StoreInterface $store): string
    {
        $urlKey = (string)($product->getData('url_key') ?: $product->getId());
        return rtrim((string)$store->getBaseUrl(), '/') . '/' . $urlKey . '.html';
    }

    /**
     * The parent's page opened on a variant's own selection.
     *
     * The same shape the results page links with. `?quissly_variant=<id>` is
     * the half the SERVER sees - the frontend plugins paint that variant's
     * image on the first render from it - and `#attributeId=optionId` is what
     * Magento's product page preselects in the browser. Without both, every
     * channel that follows the synced URL - chat above all - lands on the
     * default colour.
     *
     * @param string $parentUrl
     * @param array $selection attribute id => option id
     * @param int $variantId
     * @return string
     */
    public function variantUrl(string $parentUrl, array $selection, int $variantId): string
    {
        if ($selection === [] || $variantId <= 0) {
            return $parentUrl;
        }
        $pairs = [];
        foreach ($selection as $attributeId => $optionId) {
            $pairs[] = (int)$attributeId . '=' . (int)$optionId;
        }
        $joiner = str_contains($parentUrl, '?') ? '&' : '?';
        return $parentUrl . $joiner . 'quissly_variant=' . $variantId . '#' . implode('&', $pairs);
    }

    /**
     * Assigned categories as name + depth, lowercased, root/default excluded.
     *
     * @param Product $product
     * @return array
     */
    private function categories(Product $product): array
    {
        $out = [];
        foreach ($product->getCategoryCollection()->addAttributeToSelect('name') as $category) {
            if ((int)$category->getLevel() >= 2 && $category->getName()) {
                $out[] = ['name' => strtolower((string)$category->getName()), 'level' => (int)$category->getLevel()];
            }
        }
        return $out;
    }

    /**
     * HTML to the text a shopper reads: entities decoded, tags gone, whitespace
     * collapsed. Magento stores descriptions and option labels with entities
     * ("Lycra&reg;", "&nbsp;") which must never reach the index as literal text.
     *
     * @param string $html
     * @return string
     */
    public function plainText(string $html): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- decoding, not rendering: no escaper does this
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * "Label: a, b." - or '' when there is nothing to say.
     *
     * @param string $label
     * @param string[] $values
     * @return string
     */
    private function sentence(string $label, array $values): string
    {
        $values = array_values(array_filter(array_map('strval', $values), 'strlen'));
        if ($label === '' || $values === []) {
            return '';
        }
        $joined = implode(', ', $values);
        // A value that is itself prose already ends with punctuation.
        $stop = preg_match('/[.!?]$/u', $joined) ? '' : '.';
        return ucfirst($label) . ': ' . $joined . $stop;
    }

    /**
     * Drop null / '' / [] values, keep 0 and "0".
     *
     * @param array $values
     * @return array
     */
    private function withoutEmpty(array $values): array
    {
        return array_filter($values, static fn ($v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Whether a shopper can buy this right now, read the way the sync can trust.
     *
     * NOT $product->isSalable(). That answer depends on what was loaded onto the
     * product object, and the children returned by getUsedProducts() come from a
     * collection that carries no stock status - so an out-of-stock variant
     * reported isSalable() === true and shipped as in_stock: true. Quissly then
     * correctly replied "no changes detected", because the payload really was
     * unchanged, and the variant stayed buyable in the index for ever.
     *
     * The same product loaded fresh from the repository answers correctly in
     * every area, which is why this only ever showed up on variants.
     *
     * The stock registry reads the stock status index directly, so it does not
     * depend on how the object arrived.
     *
     * The dependency is REQUIRED, not optional-with-a-default: Magento's DI
     * uses the default for an optional constructor parameter and never injects
     * it, so the first version of this fix silently kept the old behaviour.
     *
     * @param Product $product
     * @param int $websiteId
     * @return bool
     */
    public function isInStock(Product $product, int $websiteId): bool
    {
        try {
            $status = $this->stockRegistry->getStockStatus((int)$product->getId(), $websiteId);
            return (int)$status->getStockStatus() === 1;
        } catch (\Throwable $e) {
            // A missing stock row is not a reason to drop a product from the
            // catalogue; fall back to what the object itself believes.
            return (bool)$product->isSalable();
        }
    }
}
