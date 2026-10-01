<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Plugin\Frontend;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Block\Product\View\Gallery;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Quissly\Search\Model\Search\VariantRequest;

/**
 * Renders the matched variant's images on the FIRST paint of a product page.
 *
 * Without this the page arrives showing the parent's default colour and
 * configurable.js corrects it once it runs, which a shopper sees as a flash of
 * the wrong product. The fragment cannot prevent that - it never reaches the
 * server - so the link carries ?quissly_variant= as well, and this puts it to
 * work before a byte of html is sent.
 *
 * Scoped to the GALLERY BLOCK ONLY. getProduct() is inherited from
 * AbstractProduct and used across the page; plugging it here swaps what the
 * images are built from and nothing else. Name, price, sku, add-to-cart and
 * structured data are separate block instances and still describe the parent,
 * which is the product being sold.
 */
class VariantGallery
{
    /**
     * @var array<int, Product|null>
     */
    private array $loaded = [];

    /**
     * @param VariantRequest $variantRequest
     * @param ProductRepositoryInterface $products
     */
    public function __construct(
        private readonly VariantRequest $variantRequest,
        private readonly ProductRepositoryInterface $products
    ) {
    }

    /**
     * Substitute the variant when the url asked for one and it checks out.
     *
     * @param Gallery $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetProduct(Gallery $subject, $result)
    {
        if (!$result instanceof Product || !is_numeric($result->getId())) {
            return $result;
        }
        $variantId = $this->variantRequest->variantFor((int)$result->getId());
        if ($variantId === null) {
            return $result;
        }
        $variant = $this->variant($variantId);
        // A variant with no images of its own would render an empty gallery,
        // which is worse than the parent's - and worse than the flash.
        if ($variant === null || !$this->hasGallery($variant)) {
            return $result;
        }
        return $variant;
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
                $this->loaded[$id] = null;
            }
        }
        return $this->loaded[$id];
    }

    /**
     * Whether this product has images of its own to show.
     *
     * @param Product $product
     * @return bool
     */
    private function hasGallery(Product $product): bool
    {
        $image = (string)$product->getData('image');
        return $image !== '' && $image !== 'no_selection';
    }
}
