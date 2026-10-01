<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

use Magento\Catalog\Model\Product;
use Magento\Review\Model\ReviewFactory;

/**
 * A product's native Magento review summary, as a 0-5 rating and a count.
 *
 * Magento stores the summary as a percentage (0-100); shoppers, and the chat
 * agent quoting it, think in stars. Products with no reviews yield nothing -
 * "rated 0 by 0 shoppers" is not information.
 *
 * Third-party review services (Avis Vérifiés, Yotpo…) keep their scores in
 * their own tables or inject them client-side; they are reachable here only
 * when the merchant copies the score into a product attribute, which the
 * attribute list then carries like any other.
 */
class ReviewSummary
{
    /**
     * @param ReviewFactory $reviewFactory
     */
    public function __construct(private readonly ReviewFactory $reviewFactory)
    {
    }

    /**
     * The product's review summary for a store, or null when unreviewed.
     *
     * @param Product $product
     * @param int $storeId
     * @return array|null
     */
    public function summary(Product $product, int $storeId): ?array
    {
        try {
            $this->reviewFactory->create()->getEntitySummary($product, $storeId);
        } catch (\Throwable $e) {
            return null;
        }
        $summary = $product->getRatingSummary();
        if (!is_object($summary)) {
            return null;
        }
        return $this->fromSummary($summary->getData('rating_summary'), $summary->getData('reviews_count'));
    }

    /**
     * Percent + count -> stars + count, or null when there is nothing to say.
     *
     * @param mixed $ratingPercent 0-100
     * @param mixed $count
     * @return array|null {rating: float|null, count: int}
     */
    public function fromSummary($ratingPercent, $count): ?array
    {
        $count = (int)$count;
        $percent = (float)$ratingPercent;
        if ($count <= 0) {
            return null;
        }
        // Reviews without star votes still count; a zero rating would not.
        if ($percent <= 0.0) {
            return ['rating' => null, 'count' => $count];
        }
        return ['rating' => round(min(100.0, $percent) / 20, 2), 'count' => $count];
    }
}
