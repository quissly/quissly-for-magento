<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Plugin\Frontend;

use Magento\Catalog\Model\Product;
use Quissly\Search\Model\Search\VariantFragment;
use Quissly\Search\Model\Search\VariantHints;
use Quissly\Search\Model\Search\VariantRequest;

/**
 * Points a search result at the variant that actually matched.
 *
 * qsearch answers "red tee" with the parent id and top_variant_id: the parent is
 * what Magento can render, the variant is what the shopper meant. Without this,
 * clicking the result opens the product on whichever colour it defaults to, and
 * the shopper has to find red again by hand.
 *
 * Only the LINK changes - a fragment Magento's own configurable.js already
 * understands. Nothing about the tile is restyled, no template is overridden and
 * no non-search page is touched: with no hint recorded, every URL is returned
 * exactly as Magento built it.
 */
class VariantLink
{
    /**
     * @param VariantFragment $fragment
     * @param VariantHints $hints
     */
    public function __construct(
        private readonly VariantFragment $fragment,
        private readonly VariantHints $hints
    ) {
    }

    /**
     * Append the preselection fragment when this product had a matched variant.
     *
     * Plugged into the PRODUCT, not the list block: Magento's list.phtml renders
     * the tile link as $_product->getProductUrl(), so a plugin on the block's
     * method of the same name never runs for a search result.
     *
     * @param Product $subject
     * @param string $result
     * @return string
     */
    public function afterGetProductUrl(Product $subject, $result): string
    {
        $result = (string)$result;
        if ($result === '' || !is_numeric($subject->getId())) {
            return $result;
        }
        // A url that already carries a fragment is not ours to rewrite.
        if (str_contains($result, '#')) {
            return $result;
        }
        $parentId = (int)$subject->getId();
        $fragment = $this->fragment->forParent($parentId);
        if ($fragment === '') {
            return $result;
        }
        // Twice, deliberately. The fragment is what configurable.js reads; the
        // query parameter is the only half the SERVER ever sees, and without it
        // the page paints the parent's colour before the js corrects it.
        $variantId = $this->hints->variantFor($parentId);
        if ($variantId !== null) {
            $result .= (str_contains($result, '?') ? '&' : '?')
                . VariantRequest::PARAM . '=' . $variantId;
        }
        return $result . $fragment;
    }
}
