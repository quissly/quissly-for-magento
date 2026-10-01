<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the URL fragment that preselects a configurable's matched variant.
 *
 * Magento's configurable.js reads its preselection from the URL FRAGMENT, not
 * the query string - `_overrideDefaults()` looks for `#` and parses what follows
 * as `attributeId=optionId` pairs, checking each against spConfig.attributes.
 * A query string is ignored silently, which is the trap this class exists to
 * avoid repeating.
 *
 * Resolution is one query for the whole page, run on first use: a results page
 * holds up to a page-size of parents and loading each one to read its options
 * would be a product load per tile.
 *
 * DIRECT SQL, deliberately, and the same exception PriceIndexReadiness takes.
 * The convention is ResourceModels over the tables this module declares;
 * these are Magento's own, read-only, and the alternative is a product load per
 * tile to recover two integers we can join for in one statement. Documented
 * rather than implicit, because a marketplace reviewer will ask.
 */
class VariantFragment
{
    /**
     * @var array<int, string>|null parent product id => "#93=4", built lazily
     */
    private ?array $fragments = null;

    /**
     * @param ResourceConnection $resource
     * @param VariantHints $hints
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly VariantHints $hints,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The fragment for this parent, or '' when there is nothing to preselect.
     *
     * Empty when the parent was not hinted, or when the hinted variant carries
     * no usable option values.
     *
     * @param int $parentId
     * @return string
     */
    public function forParent(int $parentId): string
    {
        if ($this->fragments === null) {
            $this->fragments = $this->build($this->hints->all());
        }
        return $this->fragments[$parentId] ?? '';
    }

    /**
     * The store whose values apply to this render.
     *
     * @return int
     */
    private function currentStoreId(): int
    {
        try {
            return (int)$this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException $e) {
            return 0;
        }
    }

    /**
     * Resolve every hinted pair in one query.
     *
     * For each hinted (parent, variant), reads the variant's value for every
     * attribute the parent is configured on.
     *
     * @param array $map parent product id => variant product id
     * @return array<int, string>
     */
    private function build(array $map): array
    {
        if ($map === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        // Both scopes, store-specific winning, which is how Magento's own EAV
        // reads resolve a value. In practice only store 0 can hold one here:
        // Configurable::canUseAttribute() refuses any attribute that is not
        // SCOPE_GLOBAL, so a super attribute has no per-store-view value to
        // override with. Reading both anyway costs nothing and means a store
        // that somehow carries a stray row is resolved the way Magento would.
        $storeId = $this->currentStoreId();
        $select = $connection->select()
            ->from(
                ['sa' => $this->resource->getTableName('catalog_product_super_attribute')],
                ['parent_id' => 'sa.product_id', 'attribute_id' => 'sa.attribute_id']
            )
            ->join(
                ['v' => $this->resource->getTableName('catalog_product_entity_int')],
                'v.attribute_id = sa.attribute_id',
                [
                    'variant_id' => 'v.entity_id',
                    'option_id' => 'v.value',
                    'value_store_id' => 'v.store_id',
                ]
            )
            ->where('sa.product_id IN (?)', array_keys($map))
            ->where('v.entity_id IN (?)', array_values($map))
            ->where('v.store_id IN (?)', [0, $storeId])
            ->where('v.value IS NOT NULL')
            ->order('v.store_id ASC');

        $pairs = [];
        foreach ($connection->fetchAll($select) as $row) {
            $parentId = (int)$row['parent_id'];
            // The join cannot express "this variant belongs to THIS parent", so
            // a shopper searching two configurables that share an attribute
            // would otherwise cross-contaminate. Filter to the hinted pair.
            if (($map[$parentId] ?? null) !== (int)$row['variant_id']) {
                continue;
            }
            // ordered store 0 first, so a store-specific row overwrites it
            $pairs[$parentId][(int)$row['attribute_id']] = (int)$row['option_id'];
        }

        $fragments = [];
        foreach ($pairs as $parentId => $selections) {
            $parts = [];
            foreach ($selections as $attributeId => $optionId) {
                $parts[] = $attributeId . '=' . $optionId;
            }
            if ($parts !== []) {
                $fragments[$parentId] = '#' . implode('&', $parts);
            }
        }
        return $fragments;
    }
}
