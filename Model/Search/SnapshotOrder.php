<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * A voice/image results page in the toolbar's sort.
 *
 * A typed search asks Quissly for the order. A voice or image result is a
 * snapshot of ids taken when the shopper spoke or sent the photo, and cannot be
 * asked again, so it is sorted here - by what Magento sorts and shows: the price
 * index's min_price for this shopper's group and website, this store view's
 * name (case-insensitive, as Quissly sorts titles), or when the product was
 * created. Same products, the order the toolbar asked for. Ties, and products
 * without the value (last), keep Quissly's order.
 */
class SnapshotOrder
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * The snapshot's ids in the sort's order.
     *
     * @param int[] $ids in Quissly's order
     * @param string $field a CriteriaInspector::QUISSLY_SORTS field
     * @param string $direction 'asc' / 'desc'
     * @return int[]
     */
    public function sort(array $ids, string $field, string $direction): array
    {
        if (count($ids) < 2 || !in_array($field, CriteriaInspector::QUISSLY_SORTS, true)) {
            return $ids;
        }
        $collection = $this->collectionFactory->create();
        $collection->addIdFilter($ids);
        if ($field === 'price') {
            $collection->addPriceData();
        } elseif ($field === 'name') {
            $collection->addAttributeToSelect('name');
        }
        // created_at is a column of the product table, which every collection reads.

        $values = [];
        foreach ($collection as $product) {
            $value = $this->value($field, $product->getData($field === 'price' ? 'min_price' : $field));
            if ($value !== null) {
                $values[(int)$product->getId()] = $value;
            }
        }
        return $this->order($ids, $values, $direction === 'desc');
    }

    /**
     * A product's value for the sort, or null when it has none.
     *
     * A created_at stays the database's 'Y-m-d H:i:s' text, which sorts as the time does.
     *
     * @param string $field
     * @param mixed $raw
     * @return float|string|null
     */
    public function value(string $field, $raw): float|string|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return $field === 'price' ? (float)$raw : ($field === 'name' ? mb_strtolower((string)$raw) : (string)$raw);
    }

    /**
     * Order ids by their values; missing values last, ties in their given order.
     *
     * @param int[] $ids
     * @param array $values float|string by product id
     * @param bool $descending
     * @return int[]
     */
    public function order(array $ids, array $values, bool $descending): array
    {
        $rows = [];
        foreach (array_values($ids) as $position => $id) {
            $rows[] = [$id, $position, $values[$id] ?? null];
        }
        usort($rows, static function (array $a, array $b) use ($descending): int {
            if ($a[2] !== $b[2]) {
                if ($a[2] === null) {
                    return 1;
                }
                if ($b[2] === null) {
                    return -1;
                }
                $cmp = $a[2] <=> $b[2];
                return $descending ? -$cmp : $cmp;
            }
            return $a[1] <=> $b[1];
        });
        return array_map(static fn (array $row) => $row[0], $rows);
    }
}
