<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * A voice/image results page sorted by price.
 *
 * A typed search asks Quissly for the price order. A voice or image result is a
 * snapshot of ids taken when the shopper spoke or sent the photo, and cannot be
 * asked again, so it is sorted here - by the price Magento sorts and shows
 * (the price index's min_price for this shopper's group and website). Same
 * products, the order the toolbar asked for. Ties, and products with no indexed
 * price (last), keep Quissly's order.
 */
class SnapshotPriceOrder
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * The snapshot's ids in price order.
     *
     * @param int[] $ids in Quissly's order
     * @param string $direction 'asc' / 'desc'
     * @return int[]
     */
    public function sort(array $ids, string $direction): array
    {
        if (count($ids) < 2) {
            return $ids;
        }
        $collection = $this->collectionFactory->create();
        $collection->addIdFilter($ids)->addPriceData();

        $prices = [];
        foreach ($collection as $product) {
            $price = $product->getData('min_price');
            if ($price !== null && $price !== '') {
                $prices[(int)$product->getId()] = (float)$price;
            }
        }
        return $this->order($ids, $prices, $direction === 'desc');
    }

    /**
     * Order ids by their prices; missing prices last, ties in their given order.
     *
     * @param int[] $ids
     * @param float[] $prices by product id
     * @param bool $descending
     * @return int[]
     */
    public function order(array $ids, array $prices, bool $descending): array
    {
        $rows = [];
        foreach (array_values($ids) as $position => $id) {
            $rows[] = [$id, $position, $prices[$id] ?? null];
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
