<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Framework\App\RequestInterface;

/**
 * Per-request memory of which variant matched, for the page being rendered.
 *
 * qsearch answers a configurable match with the parent id AND the child that
 * actually matched (top_variant_id). Magento is handed the parent - children are
 * "Not Visible Individually" and would be filtered straight back out - so the
 * child would otherwise be discarded, and a shopper searching "red tee" gets a
 * tile showing whichever colour the parent happens to default to.
 *
 * The interceptor writes the map here; the link plugin reads it while the theme
 * renders. Request-scoped and shared (etc/frontend/di.xml) because those two run
 * in the same request but have no other way to reach each other: a SearchResult
 * carries documents with ids and nothing else.
 *
 * Reads are refused outside a catalogsearch request. Nothing else writes hints,
 * so the map is already empty on a category page - but its consumers plug
 * getProductUrl and getImage, which every page in Magento calls, and "safe
 * because a map happens to be empty" is a guard nobody can see. This one is
 * explicit: if a future caller ever records a hint somewhere unexpected, deep
 * link parameters still cannot escape onto category tiles or into emails.
 */
class VariantHints
{
    /**
     * The only module whose pages these hints describe.
     */
    private const SEARCH_MODULE = 'catalogsearch';

    /**
     * @var array<int, int> parent product id => matched variant product id
     */
    private array $map = [];

    /**
     * @param RequestInterface $request
     */
    public function __construct(private readonly RequestInterface $request)
    {
    }

    /**
     * Record the matches for this request. Later searches in the same request
     * (Magento can run more than one) merge rather than overwrite.
     *
     * @param array $map parent product id => matched variant product id
     * @return void
     */
    public function remember(array $map): void
    {
        foreach ($map as $parentId => $variantId) {
            $parentId = (int)$parentId;
            $variantId = (int)$variantId;
            if ($parentId > 0 && $variantId > 0) {
                $this->map[$parentId] = $variantId;
            }
        }
    }

    /**
     * The variant that matched for this parent, or null when there was none.
     *
     * @param int $parentId
     * @return int|null
     */
    public function variantFor(int $parentId): ?int
    {
        return $this->onASearchPage() ? ($this->map[$parentId] ?? null) : null;
    }

    /**
     * Every hint recorded this request.
     *
     * @return array<int, int>
     */
    public function all(): array
    {
        return $this->onASearchPage() ? $this->map : [];
    }

    /**
     * Whether the request being rendered is a catalogsearch one.
     *
     * @return bool
     */
    private function onASearchPage(): bool
    {
        return $this->request->getModuleName() === self::SEARCH_MODULE;
    }
}
