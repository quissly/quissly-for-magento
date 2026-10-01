<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

/**
 * Outcome of one Quissly search call: a fallback-matrix classification plus,
 * when OK, the validated product ids for the requested page and the total.
 *
 * $variants maps a parent product id to the variant that actually matched, when
 * qsearch says so (top_variant_id). Empty for simple products and for every
 * response that omits the field. Ints on both sides, per the ID boundary rule.
 *
 * Pure DTO - unit-testable without Magento.
 */
class SearchOutcome
{
    /**
     * @param string $code ResponseClassifier::* constant
     * @param int[] $ids Validated positive product ids, page slice, ranked order
     * @param int $total num_total_results
     * @param array $variants parent product id => the variant that matched
     */
    public function __construct(
        public readonly string $code,
        public readonly array $ids = [],
        public readonly int $total = 0,
        public readonly array $variants = []
    ) {
    }

    /**
     * Whether the call succeeded - an EMPTY result is still ok (a real answer).
     *
     * @return bool
     */
    public function isOk(): bool
    {
        return $this->code === ResponseClassifier::OK;
    }

    /**
     * Whether the failure is an auth/billing/config class.
     *
     * These surface as a merchant alert, never as shopper-visible differences.
     *
     * @return bool
     */
    public function isAuthClass(): bool
    {
        return in_array($this->code, [
            ResponseClassifier::AUTH_ERROR,
            ResponseClassifier::CLOCK_SKEW_SUSPECTED,
            ResponseClassifier::PAYMENT_REQUIRED,
            ResponseClassifier::FORBIDDEN,
        ], true);
    }
}
