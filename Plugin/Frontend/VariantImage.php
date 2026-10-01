<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Plugin\Frontend;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Quissly\Search\Model\Search\VariantHints;

/**
 * Renders the matched variant's picture on a search result tile.
 *
 * The link already opens the right colour; the tile still showed whichever the
 * parent defaults to, so "red tee" returned a blue photo and the result looked
 * wrong before the shopper clicked anything.
 *
 * This is Magento's own pattern, not a results UI of our own: Swatches ships
 * Model\Plugin\ProductImage, a before-plugin on this very method, which swaps
 * the parent for a child when a swatch filter is active on a category page.
 * The mechanism is identical - only the trigger differs, ours being the variant
 * qsearch named rather than a url filter.
 *
 * Substitution is limited to the IMAGE argument. Name, price and link still come
 * from the parent, which is the sellable product; a tile is never assembled out
 * of two different products beyond the picture.
 */
class VariantImage
{
    /**
     * Variants already loaded this request, by product id.
     *
     * @var array<int, Product|null>
     */
    private array $loaded = [];

    /**
     * @param VariantHints $hints
     * @param ProductRepositoryInterface $products
     */
    public function __construct(
        private readonly VariantHints $hints,
        private readonly ProductRepositoryInterface $products
    ) {
    }

    /**
     * Swap in the matched variant before the image is built.
     *
     * @param AbstractProduct $subject
     * @param Product $product
     * @param string $location
     * @param array $attributes
     * @return array
     */
    public function beforeGetImage(
        AbstractProduct $subject,
        $product,
        $location = '',
        array $attributes = []
    ): array {
        if (!$product instanceof Product || !is_numeric($product->getId())) {
            return [$product, $location, $attributes];
        }
        $variantId = $this->hints->variantFor((int)$product->getId());
        if ($variantId === null) {
            return [$product, $location, $attributes];
        }
        $variant = $this->variant($variantId);
        // A variant with no image of its own must not blank the tile: the
        // parent's picture is a worse answer than the right one, and a better
        // answer than none.
        if ($variant === null || !$this->hasImage($variant)) {
            return [$product, $location, $attributes];
        }
        return [$variant, $location, $attributes];
    }

    /**
     * Load a variant once per request.
     *
     * @param int $id
     * @return Product|null
     */
    private function variant(int $id): ?Product
    {
        if (!array_key_exists($id, $this->loaded)) {
            try {
                $product = $this->products->getById($id);
                $this->loaded[$id] = $product instanceof Product ? $product : null;
            } catch (NoSuchEntityException $e) {
                // The variant was deleted between the search and the render.
                $this->loaded[$id] = null;
            }
        }
        return $this->loaded[$id];
    }

    /**
     * Whether this product has a usable image.
     *
     * @param Product $product
     * @return bool
     */
    private function hasImage(Product $product): bool
    {
        $image = (string)$product->getData('small_image') ?: (string)$product->getData('image');
        return $image !== '' && $image !== 'no_selection';
    }
}
