<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Cron;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\FlagManager;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Sync\Enqueuer;
use Quissly\Search\Model\Sync\StockSnapshot;

/**
 * Every minute: ask Magento's stock registry about a chunk of the products
 * Quissly holds, and re-queue those whose in_stock no longer matches what
 * was sent. See StockSnapshot for why this exists and why it reads the
 * registry rather than a table.
 *
 * Chunked with a cursor per website, so a large catalogue is walked over a
 * few runs rather than blocking cron; a 10,000-product store is fully
 * compared about every five minutes. Variants are enqueued as themselves and
 * resolved to their parent by the enqueuer.
 */
class StockReconcileCron
{
    /** Products checked per website per run. */
    public const CHUNK = 2000;

    private const CURSOR_FLAG = 'quissly_stock_reconcile_cursor_w';

    /**
     * @param StockSnapshot $snapshot
     * @param StockRegistryInterface $stockRegistry
     * @param Enqueuer $enqueuer
     * @param Settings $settings
     * @param StoreManagerInterface $storeManager
     * @param FlagManager $flagManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StockSnapshot $snapshot,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly Enqueuer $enqueuer,
        private readonly Settings $settings,
        private readonly StoreManagerInterface $storeManager,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Compare one chunk per configured website and re-queue the differences.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            if (!$this->settings->isConfigured($websiteId === 0 ? null : $websiteId)) {
                continue;
            }
            $cursor = (int)($this->flagManager->getFlagData(self::CURSOR_FLAG . $websiteId) ?? 0);
            $chunk = $this->snapshot->chunk($websiteId, $cursor, self::CHUNK);
            if ($chunk === []) {
                // End of the table: start over next run.
                $this->flagManager->saveFlag(self::CURSOR_FLAG . $websiteId, 0);
                continue;
            }
            $changed = 0;
            foreach ($chunk as $productId => $sentFlag) {
                $now = $this->currentFlag($productId, $websiteId);
                if ($now !== null && $now !== $sentFlag) {
                    $this->enqueuer->enqueueUpsert($productId, [$websiteId]);
                    $changed++;
                }
            }
            $this->flagManager->saveFlag(self::CURSOR_FLAG . $websiteId, (int)array_key_last($chunk));
            if ($changed > 0) {
                // Counts only, never which products.
                $this->logger->info(sprintf(
                    '[quissly] stock reconcile website=%d checked=%d re-queued=%d whose stock changed',
                    $websiteId,
                    count($chunk),
                    $changed
                ));
            }
        }
    }

    /**
     * What the mapper would send for this product now, or null if unknown.
     *
     * @param int $productId
     * @param int $websiteId
     * @return int|null
     */
    private function currentFlag(int $productId, int $websiteId): ?int
    {
        try {
            return (int)$this->stockRegistry->getStockStatus($productId, $websiteId)->getStockStatus() === 1 ? 1 : 0;
        } catch (\Throwable $e) {
            // A product with no stock row (or deleted since): nothing to compare.
            return null;
        }
    }
}
