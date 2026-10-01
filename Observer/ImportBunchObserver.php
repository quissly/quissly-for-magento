<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Sync\Enqueuer;

/**
 * CSV import → enqueue upserts (enqueue only).
 *
 * Bulk import writes products without firing catalog_product_save_after, so
 * without this observer an imported catalog reaches Quissly only when the
 * reconciliation cron next runs - or when a merchant knows to press full sync,
 * which they should never have to. This is the event Magento does fire, once
 * per written bunch.
 *
 * The payload carries raw CSV ROWS, not products: no ids, and for a new SKU no
 * id existed when the row was read. The adapter's sku-to-entity map is the
 * authoritative source, and it covers rows the import created as well as ones
 * it updated.
 */
class ImportBunchObserver implements ObserverInterface
{
    /**
     * @param Enqueuer $enqueuer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Enqueuer $enqueuer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $bunch = $observer->getEvent()->getData('bunch');
        if (!is_array($bunch) || $bunch === []) {
            return;
        }

        $adapter = $observer->getEvent()->getData('adapter');
        $skuToId = $this->skuMap($adapter);

        $queued = 0;
        foreach ($bunch as $row) {
            $sku = is_array($row) ? trim((string)($row['sku'] ?? '')) : '';
            if ($sku === '') {
                continue;
            }

            $productId = $skuToId[strtolower($sku)] ?? null;
            if ($productId === null) {
                continue;
            }

            // Website assignment is left empty on purpose: the row may not
            // carry it, and Enqueuer resolves the product's real websites.
            // Guessing here would queue the product against the wrong store.
            $this->enqueuer->enqueueUpsert($productId);
            $queued++;
        }

        if ($queued > 0) {
            // Ids and counts only, never row contents.
            $this->logger->info(sprintf('[quissly] import bunch queued %d product(s)', $queued));
        }
    }

    /**
     * Lowercased sku => entity id, from the import adapter.
     *
     * Magento keys getNewSku() by lowercased sku and includes every sku the
     * run has seen, created or updated. A sku the map does not carry is
     * skipped rather than looked up individually - that would be one query per
     * row on a large import - and reconciliation covers the remainder.
     *
     * @param mixed $adapter
     * @return array<string, int>
     */
    private function skuMap($adapter): array
    {
        if (!is_object($adapter) || !method_exists($adapter, 'getNewSku')) {
            return [];
        }

        $map = [];
        foreach ((array)$adapter->getNewSku() as $sku => $data) {
            $entityId = is_array($data) ? (int)($data['entity_id'] ?? 0) : 0;
            if ($entityId > 0) {
                $map[strtolower((string)$sku)] = $entityId;
            }
        }

        return $map;
    }
}
