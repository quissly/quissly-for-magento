<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Framework\Api\Search\SearchCriteriaInterface;

/**
 * The guard's criteria analysis (conditions 1 - 4 and 6).
 * Every rule here is spike-verified against real 2.4.8 behavior. Pure logic -
 * unit-testable with a mocked criteria.
 */
class CriteriaInspector
{
    public const REQUEST_NAME = 'quick_search_container';

    /**
     * Filter fields present on EVERY unrefined storefront search
     * (spike-verified baseline). Any other field = shopper refinement → bail.
     */
    private const BASELINE_FILTER_FIELDS = [
        'visibility',
        'search_term',
        'price_dynamic_algorithm',
        'category_ids_to_aggregate',
    ];

    /**
     * Reason for a request that is no storefront search at all (category
     * listings, GraphQL, admin): not something to report as a skip.
     */
    public const NOT_A_SEARCH = 'not-a-search';

    /**
     * Sort fields that keep interception honest.
     *
     * Relevance plus the engine's entity_id tie-breaker; anything else (price,
     * name…) bails so native sorting stays truthful (spike-reproduced hazard).
     *
     * is_out_of_stock is not a shopper's choice: MSI adds it to every listing
     * when "Display Out of Stock Products" is Yes, and on a search page (no
     * category) always ("out of stock to bottom",
     * CollectionPlugin::applyOutOfStockSortOrders). Quissly returns in-stock
     * products only, so nothing is lost by answering such a search. Without it,
     * every search on such a store skipped Quissly silently (Cotton & Co,
     * 2.4.7-p10, 2026-09-25).
     */
    private const ALLOWED_SORT_FIELDS = ['relevance', 'entity_id', 'is_out_of_stock'];

    /**
     * Sequences the backend rejects with a 422 - pre-screened
     * so we never spend a signed request or log an error on shopper typing.
     */
    private const DANGEROUS_SEQUENCES = ['--', '../', '..\\', "\0", '/*'];

    /**
     * Whether this criteria is an interceptable storefront quick search.
     *
     * @param SearchCriteriaInterface $criteria
     * @return bool
     */
    public function isInterceptable(SearchCriteriaInterface $criteria): bool
    {
        return $this->rejectionReason($criteria) === null;
    }

    /**
     * Why this criteria is not interceptable, or null when it is.
     *
     * The first failed condition, in guard order: NOT_A_SEARCH, 'no-term',
     * 'filter:<field>', 'sort:<field>', 'term'. Field names only - the
     * shopper's query text is never part of a reason.
     *
     * @param SearchCriteriaInterface $criteria
     * @return string|null
     */
    public function rejectionReason(SearchCriteriaInterface $criteria): ?string
    {
        if ($criteria->getRequestName() !== self::REQUEST_NAME) {
            return self::NOT_A_SEARCH;
        }
        $term = $this->searchTerm($criteria);
        if ($term === null) {
            return 'no-term';
        }
        $filter = $this->refinementFilter($criteria);
        if ($filter !== null) {
            return 'filter:' . $filter;
        }
        $sort = $this->foreignSort($criteria);
        if ($sort !== null) {
            return 'sort:' . $sort;
        }
        return $this->isTermSane($term) ? null : 'term';
    }

    /**
     * The shopper's query text from the search_term filter; null when absent.
     *
     * @param SearchCriteriaInterface $criteria
     * @return string|null
     */
    public function searchTerm(SearchCriteriaInterface $criteria): ?string
    {
        foreach ((array)$criteria->getFilterGroups() as $group) {
            foreach ($group->getFilters() as $filter) {
                if ($filter->getField() === 'search_term') {
                    $value = $filter->getValue();
                    return is_string($value) ? $value : null;
                }
            }
        }
        return null;
    }

    /**
     * Whether any filter beyond the spike-verified baseline is present
     * (layered-nav refinement → native must handle it).
     *
     * @param SearchCriteriaInterface $criteria
     * @return bool
     */
    public function hasRefinementFilters(SearchCriteriaInterface $criteria): bool
    {
        return $this->refinementFilter($criteria) !== null;
    }

    /**
     * The first filter field beyond the baseline, or null when there is none.
     *
     * @param SearchCriteriaInterface $criteria
     * @return string|null
     */
    private function refinementFilter(SearchCriteriaInterface $criteria): ?string
    {
        foreach ((array)$criteria->getFilterGroups() as $group) {
            foreach ($group->getFilters() as $filter) {
                $field = (string)$filter->getField();
                // price arrives as price.from / price.to at this layer.
                $root = explode('.', $field)[0];
                if (!in_array($field, self::BASELINE_FILTER_FIELDS, true)
                    && !in_array($root, self::BASELINE_FILTER_FIELDS, true)
                ) {
                    return $field;
                }
            }
        }
        return null;
    }

    /**
     * Whether the sort orders contain only relevance (+ entity_id tie-breaker).
     *
     * Sort orders at this layer are a plain field => direction map (spike-verified).
     *
     * @param SearchCriteriaInterface $criteria
     * @return bool
     */
    public function isRelevanceSortOnly(SearchCriteriaInterface $criteria): bool
    {
        return $this->foreignSort($criteria) === null;
    }

    /**
     * The first sort field outside ALLOWED_SORT_FIELDS, or null when there is none.
     *
     * @param SearchCriteriaInterface $criteria
     * @return string|null
     */
    private function foreignSort(SearchCriteriaInterface $criteria): ?string
    {
        foreach ((array)$criteria->getSortOrders() as $field => $direction) {
            $name = is_object($direction) ? (string)$direction->getField() : (string)$field;
            if (!in_array($name, self::ALLOWED_SORT_FIELDS, true)) {
                return $name;
            }
        }
        return null;
    }

    /**
     * 2 - 150 chars after trim, no backend-rejected sequences.
     *
     * @param string $term
     * @return bool
     */
    public function isTermSane(string $term): bool
    {
        $trimmed = trim($term);
        $length = mb_strlen($trimmed);
        if ($length < 2 || $length > 150) {
            return false;
        }
        foreach (self::DANGEROUS_SEQUENCES as $sequence) {
            if (str_contains($trimmed, $sequence)) {
                return false;
            }
        }
        return true;
    }
}
