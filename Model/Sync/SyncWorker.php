<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Api\CatalogClient;
use Quissly\Search\Model\Api\ResponseClassifier;
use Quissly\Search\Model\Api\SignerException;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Health\HealthRecorder;

/**
 * The sync worker: claims queue batches per website, maps, routes
 * add-vs-update via the ingest marker, sends, settles the queue on Quissly's
 * answer to the send, and opens the first-sync gate when a full sync drains.
 * Runs from cron and the quissly:sync CLI.
 *
 * Settled the way the Quissly Shopify app settles (2026-10-07): a 2xx answer to
 * the POST/PUT/DELETE is the product delivered - marked ingested, removed from
 * the queue, its stock snapshotted. Anything else (no answer, a timeout, any
 * other status) leaves it queued for the next run, up to MAX_ATTEMPTS. The
 * operation's status (GET /v1beta/catalog/{op}/{ts}) is not read: no waiting
 * on it, nothing carried between runs, no per-item verdicts.
 */
class SyncWorker
{
    /**
     * Products per batch - sendable products, NOT queue rows.
     *
     * The distinction is the whole point: children of a configurable occupy
     * queue rows but are never sent on their own (they ride inside the
     * parent's variants[]), so counting rows made the real batch size a
     * function of how many variants the catalogue happens to have.
     * collectBatch() keeps claiming until it has this many products to send.
     */
    public const BATCH_SIZE = 50;

    /** Queue rows per claim; several claims may feed one batch. */
    public const CLAIM_ROWS = 250;

    /**
     * Hard ceiling on records in a single catalog call (batches ≤250).
     *
     * Accumulating to BATCH_SIZE can overshoot by up to one claim's worth of
     * rows, so the wire cap is enforced where the call is made.
     */
    public const MAX_RECORDS_PER_CALL = 50;

    /**
     * Records on the wire per call, parents AND their variants counted. The
     * backend limits only parent ids; this cap is for the merchant's upstream
     * bandwidth - fifty apparel parents at 200 variants each is 10,000 records
     * in one POST. A parent is never split across calls (2026-09-10).
     */
    public const MAX_WIRE_RECORDS_PER_CALL = 2500;

    /**
     * Claims one batch may make before giving up on filling itself.
     *
     * A queue that is all variant rows would otherwise loop until the whole
     * queue was scanned. Bounded, the run simply continues on the next pass.
     */
    private const MAX_CLAIMS_PER_BATCH = 20;

    public const MAX_ATTEMPTS = 3;

    /**
     * How long a "running" full sync may go unmentioned before it is dead.
     *
     * Cron calls run() every minute per website and every run writes
     * last_run, so a live sync refreshes this within a minute or two. Ten
     * minutes of silence means no worker is coming back.
     */
    public const STALE_RUN_SECONDS = 600;

    /**
     * How long a row may wait for the price indexer before we give up on it.
     *
     * Deferred rows deliberately do NOT burn a send attempt - waiting for an
     * index is not a failed delivery, and spending the three send attempts on
     * it would drop a perfectly good product. Age bounds it instead, so a row
     * that somehow never becomes mappable cannot sit in the queue for ever.
     */
    public const MAX_INDEX_WAIT_SECONDS = 21600;

    /**
     * How long a deferred row steps aside before it may be claimed again.
     *
     * Short, because the price indexer runs every minute on a scheduled store -
     * but long enough that a block of undeliverable rows cannot be re-claimed
     * on every run and starve the deliverable rows behind them.
     */
    public const DEFER_SECONDS = 300;

    /** How long to wait for a running indexer cron: attempts x microseconds. */
    private const INDEXER_WAIT_ATTEMPTS = 10;
    private const INDEXER_WAIT_MICROSECONDS = 500000;

    /**
     * Queue version each product was claimed at this run, by product id.
     *
     * @var array<int, int>
     */
    private array $claimedVersions = [];

    /** One drain per website at a time; see run(). */
    private const LOCK_PREFIX = 'quissly_sync_w';
    private const PROGRESS_FLAG_PREFIX = 'quissly_sync_progress_w';

    /** @var RecordPacker */
    private RecordPacker $packer;

    /**
     * @param QueueResource $queue
     * @param ProductMapper $mapper
     * @param CatalogClient $client
     * @param CollectionFactory $productCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param FlagManager $flagManager
     * @param FirstSyncGate $gate
     * @param Settings $settings
     * @param LoggerInterface $logger
     * @param LockManagerInterface $lockManager
     * @param HealthRecorder $health
     * @param PriceIndexReadiness $readiness
     * @param IndexRefresher $refresher
     * @param StockSnapshot $snapshot
     * @param RecordPacker|null $packer
     * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
     */
    public function __construct(
        private readonly QueueResource $queue,
        private readonly ProductMapper $mapper,
        private readonly CatalogClient $client,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly FlagManager $flagManager,
        private readonly FirstSyncGate $gate,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        private readonly LockManagerInterface $lockManager,
        private readonly HealthRecorder $health,
        private readonly PriceIndexReadiness $readiness,
        private readonly IndexRefresher $refresher,
        private readonly StockSnapshot $snapshot,
        ?RecordPacker $packer = null
    ) {
        $this->packer = $packer ?? new RecordPacker();
    }

    /**
     * Enqueue every eligible product of a website and start progress tracking.
     *
     * @param int $websiteId
     * @return int Enqueued count
     */
    public function startFullSync(int $websiteId): int
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addWebsiteFilter($websiteId)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter(
                'visibility',
                ['in' => [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]]
            )
            ->addAttributeToFilter('type_id', ['in' => ProductMapper::SYNCABLE_TYPES]);
        $ids = array_map('intval', $collection->getAllIds());
        foreach ($ids as $id) {
            $this->queue->enqueue($id, $websiteId, QueueResource::OP_UPSERT);
        }
        $this->flagManager->saveFlag(self::PROGRESS_FLAG_PREFIX . $websiteId, [
            'running' => true,
            'total' => count($ids),
            'ok' => 0,
            'failed' => 0,
            'started_at' => time(),
            'finished_at' => null,
        ]);
        $this->logger->info(sprintf('[quissly] full sync started website=%d items=%d', $websiteId, count($ids)));
        return count($ids);
    }

    /**
     * Process up to $maxBatches queue batches for a website.
     *
     * @param int $websiteId
     * @param int $maxBatches
     * @return array{sent: int, ok: int, failed: int, pending: int}
     */
    public function run(int $websiteId, int $maxBatches = 1): array
    {
        // Nothing in the queue marks a row as claimed - claimBatch is a plain
        // SELECT, so two consumers take the same rows. Cron runs every minute
        // and the CLI drain command invites an operator to run concurrently,
        // which is not a hypothetical race but the documented workflow.
        //
        // Double consumption is worse than wasted calls here: a duplicate add
        // returns "already exists" and the item is then stored but never
        // indexed, producing synced-but-unsearchable products.
        $lock = self::LOCK_PREFIX . $websiteId;
        if (!$this->lockManager->lock($lock, 0)) {
            $this->logger->info(sprintf(
                '[quissly] sync already running for website=%d - skipping this pass',
                $websiteId
            ));
            // Same shape as a normal run, with the REAL pending count. A
            // stand-down that reported pending=0 would tell an operator the
            // queue was empty while another consumer was working through a
            // full one - the precise opposite of the truth, on the command
            // whose only job is saying where the sync stands.
            return [
                'sent' => 0,
                'ok' => 0,
                'failed' => 0,
                'pending' => $this->queue->countPending($websiteId),
                'skipped' => true,
            ];
        }

        try {
            return $this->runLocked($websiteId, $maxBatches);
        } finally {
            $this->lockManager->unlock($lock);
        }
    }

    /**
     * The drain itself, with exclusivity already guaranteed by run().
     *
     * Each batch is settled by Quissly's answer to its send, so a run costs
     * one round trip per call and nothing is left waiting for the next run.
     *
     * @param int $websiteId
     * @param int $maxBatches
     * @return array
     */
    private function runLocked(int $websiteId, int $maxBatches = 1): array
    {
        $totals = ['sent' => 0, 'ok' => 0, 'failed' => 0, 'pending' => 0];
        $touchedThisRun = [];
        if (!$this->settings->isConfigured($websiteId === 0 ? null : $websiteId)) {
            $totals['pending'] = $this->queue->countPending($websiteId);
            return $totals; // leave the queue untouched until configured
        }
        $this->claimedVersions = [];
        for ($i = 0; $i < $maxBatches; $i++) {
            $batch = $this->collectBatch($websiteId, $touchedThisRun);
            if ($batch === null) {
                break;
            }
            $result = $this->dispatchBatch($websiteId, $batch);
            $totals['sent'] += $result['sent'];
            $totals['ok'] += $result['ok'];
            $totals['failed'] += $result['failed'];
            if ($result['rejected']) {
                // The account was refused, not the payload: every further batch
                // would be refused identically. Stop dispatching.
                break;
            }
        }
        $totals['pending'] = $this->queue->countPending($websiteId);
        $this->updateProgress(
            $websiteId,
            $totals['ok'],
            $totals['failed'],
            $totals['pending'],
            $this->queue->countPendingProducts($websiteId)
        );
        return $totals;
    }

    /**
     * Claim queue rows until the batch holds BATCH_SIZE sendable products.
     *
     * BATCH_SIZE used to be the number of queue ROWS claimed, which on a
     * catalogue of configurables is not the same quantity at all. A
     * configurable's children are enqueued by ordinary save events but are Not
     * Visible Individually, so each one is ineligible on its own and rides
     * inside its parent's variants[] instead. Claiming 50 rows off a queue of
     * 34 parents and 500 children therefore yielded THREE products per send -
     * the observed items=9, 3, 3, 3 - and turned a 34-product catalogue into a
     * dozen round trips.
     *
     * So claim in chunks and keep going until 50 sendable products have
     * accumulated. The children are still claimed and dropped rather than
     * filtered out in SQL, and that is not incidental: a row that is never
     * claimed is never removed, countPending() never reaches 0, and the
     * first-sync gate - which opens on a drained queue - never opens.
     *
     * @param int $websiteId
     * @param int[] $touchedThisRun
     * @return array{records: array, variant_map: array, delete_ids: int[]}|null Null when nothing was claimable
     */
    private function collectBatch(int $websiteId, array &$touchedThisRun): ?array
    {
        $records = [];
        $variantMap = [];
        $deleteIds = [];
        $claimedAnything = false;
        for ($claim = 0; $claim < self::MAX_CLAIMS_PER_BATCH; $claim++) {
            $rows = $this->queue->claimBatch($websiteId, self::CLAIM_ROWS, $touchedThisRun);
            if ($rows === []) {
                break;
            }
            $claimedAnything = true;
            $upsertIds = [];
            foreach ($rows as $row) {
                $productId = (int)$row['product_id'];
                $this->claimedVersions[$productId] = (int)($row['version'] ?? 0);
                $touchedThisRun[] = $productId;
                if ($row['op'] === QueueResource::OP_DELETE) {
                    // Keyed by id so accumulating across claims dedupes without
                    // an array_merge per iteration.
                    $deleteIds[$productId] = $productId;
                } else {
                    $upsertIds[] = $productId;
                }
            }
            [$mapped, $mappedVariants, $demotedToDelete] = $this->mapClaimed($websiteId, $upsertIds);
            $records += $mapped;
            $variantMap += $mappedVariants;
            foreach ($demotedToDelete as $demotedId) {
                $deleteIds[$demotedId] = $demotedId;
            }
            if (count($records) >= self::BATCH_SIZE || count($deleteIds) >= self::BATCH_SIZE) {
                break;
            }
        }
        if (!$claimedAnything) {
            return null;
        }
        return [
            'records' => $records,
            'variant_map' => $variantMap,
            'delete_ids' => array_values($deleteIds),
        ];
    }

    /**
     * Map one claim's worth of upsert rows, settling the ones that cannot go.
     *
     * @param int $websiteId
     * @param int[] $upsertIds
     * @return array{0: array, 1: array, 2: int[]}
     */
    private function mapClaimed(int $websiteId, array $upsertIds): array
    {
        [$records, $variantMap, $unmappable, $demotedToDelete, $deferred] =
            $this->mapProducts($websiteId, $upsertIds);
        // Unmappable rows can never succeed - drop them from the queue with a log.
        $this->queue->remove($unmappable, $websiteId, $this->versionsFor($unmappable));
        // Deferred rows are waiting on the price indexer, not failing. They stay
        // queued and keep their attempts, so the wait cannot spend the send
        // budget; only age retires them, and that is logged separately.
        if ($deferred !== []) {
            // Step them aside so the queue can drain past them, then retire the
            // ones that have been waiting far too long to ever be coming.
            $this->queue->deferUntil($deferred, $websiteId, self::DEFER_SECONDS);
            $expired = $this->queue->removeOlderThan($deferred, $websiteId, self::MAX_INDEX_WAIT_SECONDS);
            if ($expired !== []) {
                $this->logger->warning(sprintf(
                    '[quissly] dropped %d rows never price-indexed within %ds website=%d',
                    count($expired),
                    self::MAX_INDEX_WAIT_SECONDS,
                    $websiteId
                ));
            }
        }
        return [$records, $variantMap, $demotedToDelete];
    }

    /**
     * Route one collected batch, send it and settle it on the answer.
     *
     * @param int $websiteId
     * @param array $batch
     * @return array{sent: int, ok: int, failed: int, rejected: bool}
     */
    private function dispatchBatch(int $websiteId, array $batch): array
    {
        $records = $batch['records'];
        $ok = 0;
        $failed = 0;
        $sent = 0;
        $rejected = false;

        if ($records !== []) {
            $ingested = $this->queue->ingestedMap(array_map('intval', array_keys($records)), $websiteId);
            $adds = [];
            $updates = [];
            foreach ($records as $id => $record) {
                if ($ingested[(int)$id] ?? false) {
                    $updates[$id] = $record;
                } else {
                    $adds[$id] = $record;
                }
            }
            foreach ([['POST', $adds], ['PUT', $updates]] as [$method, $subset]) {
                if ($subset === []) {
                    continue;
                }
                // One call carries at most MAX_RECORDS_PER_CALL records
                // ("batches ≤250"). Accumulating to BATCH_SIZE can overshoot
                // by up to one claim's worth, so the cap is enforced here
                // rather than assumed from the batch size.
                $chunks = $this->packer->pack($subset, self::MAX_RECORDS_PER_CALL, self::MAX_WIRE_RECORDS_PER_CALL);
                foreach ($chunks as $chunk) {
                    $sent += count($chunk);
                    $result = $this->dispatch($method, $chunk, $websiteId);
                    $ok += $result['ok'];
                    $failed += $result['failed'];
                    $rejected = $rejected || $result['rejected'];
                }
            }
        }

        if ($batch['delete_ids'] !== []) {
            foreach (array_chunk($batch['delete_ids'], self::MAX_RECORDS_PER_CALL) as $chunk) {
                $sent += count($chunk);
                $outcome = $this->deleteAndSettle($chunk, $websiteId);
                $ok += $outcome['ok'];
                $failed += $outcome['failed'];
                $rejected = $rejected || ($outcome['rejected'] ?? false);
            }
        }

        return [
            'sent' => $sent,
            'ok' => $ok,
            'failed' => $failed,
            'rejected' => $rejected,
        ];
    }

    /**
     * Load + map queued upserts.
     *
     * @param int $websiteId
     * @param int[] $productIds
     * @return array{0: array<string, array>, 1: array<string, string>, 2: int[], 3: int[], 4: int[]}
     */
    private function mapProducts(int $websiteId, array $productIds): array
    {
        if ($productIds === []) {
            return [[], [], [], [], []];
        }
        $store = $this->defaultStoreOfWebsite($websiteId);
        $codes = $this->settings->metadataAttributes($websiteId ?: null);
        // Codes only, never values: lets a merchant verify from the log which
        // attribute list a re-sync carried (payloads are never logged).
        $this->logger->info(sprintf(
            '[quissly] mapping %d products with attributes: %s',
            count($productIds),
            $codes === [] ? 'none' : implode(',', $codes)
        ));
        // Ids the indexer cron still owes work for are rebuilt HERE, before
        // the load, so the rows read below are current whichever process won
        // the race this minute. Only when the rebuild fails do they fall back
        // to waiting (the deferral further down).
        $fresh = [];
        $stale = $this->readiness->awaitingReindex($productIds);
        if ($stale !== [] && !$this->awaitIdleIndexer()) {
            // The cron is applying these very changes right now. Rebuilding
            // alongside it can leave its older rows written last; reading
            // alongside it can catch them half-applied. Come back next run.
            $this->logger->info(sprintf(
                '[quissly] indexer busy, holding %d products website=%d',
                count($productIds),
                $websiteId
            ));
            return [[], [], [], [], array_map('intval', $productIds)];
        }
        if ($stale !== []) {
            if ($this->refresher->refresh($stale)) {
                $fresh = $stale;
            }
            $this->logger->info(sprintf(
                '[quissly] refreshed indexes for %d of %d products with pending changes website=%d',
                count($fresh),
                count($stale),
                $websiteId
            ));
        }
        [$records, $variantMap, $found, $ineligible] =
            $this->loadAndMap($productIds, $store, $websiteId, $codes, true);
        // A row can go missing from that collection for two unrelated reasons,
        // and they must not be conflated. addPriceData() INNER JOINs the price
        // index, which on a store indexing on schedule trails the save that
        // queued the row - so a brand-new product is absent simply because it
        // has not been indexed yet. Dropping it as stale threw the product away
        // and reported a clean run.
        $missing = array_values(array_diff(array_map('intval', $productIds), $found));
        $deferred = [];
        $loadedWithoutIndex = [];
        if ($missing !== []) {
            $stillExist = $this->readiness->existing($missing);
            // Gone from the entity table: genuinely stale. Real deletions arrive
            // as their own delete ops, so nothing is lost by dropping these.
            $missing = array_values(array_diff($missing, $stillExist));
            // Still a product, but assigned to no website of ours: it will never
            // gain a price-index row here, so waiting would be waiting for ever.
            // That is ordinary ineligibility, which the caller already handles.
            $assigned = $this->readiness->assignedToWebsite($stillExist, $websiteId);
            $ineligible = array_values(array_unique(array_merge(
                $ineligible,
                array_values(array_diff($stillExist, $assigned))
            )));
            // An OUT-OF-STOCK product has no price-index row either - Magento
            // drops it from the index unless "display out of stock products" is
            // on - and no amount of waiting brings the row back. Treating that
            // as "not indexed yet" deferred the row until the age bound dropped
            // it, so a product going out of stock never reached Quissly and
            // kept being offered as buyable. Load those without the index
            // join: the record carries in_stock=false and the product's own
            // price, which is what the merchant wants Quissly to know.
            $outOfStock = $this->readiness->outOfStock($assigned);
            if ($outOfStock !== []) {
                [$oosRecords, $oosMap, $oosFound, $oosIneligible] =
                    $this->loadAndMap($outOfStock, $store, $websiteId, $codes, false);
                $records += $oosRecords;
                $variantMap += $oosMap;
                $ineligible = array_values(array_unique(array_merge($ineligible, $oosIneligible)));
                $this->logger->info(sprintf(
                    '[quissly] mapped %d out-of-stock products without a price-index row website=%d',
                    count($oosFound),
                    $websiteId
                ));
                $assigned = array_values(array_diff($assigned, $oosFound));
                $loadedWithoutIndex = $oosFound;
            }
            $deferred = $assigned;
        }
        // Rows that DID map can still carry stale prices: their values come from
        // the same lagging index. Sending one ships the PREVIOUS price under a
        // successful verdict, which quietly corrupts price sorting on Quissly's
        // side. Hold them until the indexer has caught up.
        if ($records !== []) {
            // ...except the ones loaded without the index: their prices come
            // from the product itself, so a pending reindex cannot stale them.
            $mappedIds = array_values(array_diff(array_map('intval', array_keys($records)), $loadedWithoutIndex));
            $pending = array_diff(
                array_merge(
                    $this->readiness->awaitingReindex($mappedIds),
                    $this->readiness->awaitingStockReindex($loadedWithoutIndex)
                ),
                $fresh
            );
            foreach ($pending as $id) {
                unset($records[(string)$id], $records[$id]);
                $deferred[] = $id;
            }
        }
        $deferred = array_values(array_unique($deferred));
        if ($deferred !== []) {
            $this->logger->info(sprintf(
                '[quissly] deferred %d queue rows awaiting price reindex website=%d',
                count($deferred),
                $websiteId
            ));
        }
        // Ineligible rows transitioned out of eligibility since enqueue: demote
        // to a DELETE when ever ingested, else just drop.
        $ingestedMap = $this->queue->ingestedMap($ineligible, $websiteId);
        $demotedToDelete = array_values(array_filter(
            $ineligible,
            static fn (int $id): bool => $ingestedMap[$id] ?? false
        ));
        $unmappable = array_values(array_merge($missing, array_diff($ineligible, $demotedToDelete)));
        if ($unmappable !== []) {
            $this->logger->info(sprintf(
                '[quissly] dropped %d unmappable queue rows website=%d (stale=%d ineligible=%d)',
                count($unmappable),
                $websiteId,
                count($missing),
                count($unmappable) - count($missing)
            ));
        }
        return [$records, $variantMap, $unmappable, $demotedToDelete, $deferred];
    }

    /**
     * The in_stock flag of every record and variant, by product id.
     *
     * @param array $records wire records keyed by id
     * @return array<int, int>
     */
    private function stockFlags(array $records): array
    {
        $flags = [];
        foreach ($records as $record) {
            if (isset($record['id'])) {
                $flags[(int)$record['id']] = empty($record['in_stock']) ? 0 : 1;
            }
            foreach ($record['variants'] ?? [] as $variant) {
                if (isset($variant['id'])) {
                    $flags[(int)$variant['id']] = empty($variant['in_stock']) ? 0 : 1;
                }
            }
        }
        return $flags;
    }

    /**
     * The queue versions to settle these ids at: this run's claims.
     *
     * Ids not claimed this run settle unconditionally, as they did before
     * versions existed.
     *
     * @param int[] $productIds
     * @return array<int, int>
     */
    private function versionsFor(array $productIds): array
    {
        $out = [];
        foreach ($productIds as $id) {
            if (isset($this->claimedVersions[(int)$id])) {
                $out[(int)$id] = $this->claimedVersions[(int)$id];
            }
        }
        return $out;
    }

    /**
     * Give a running indexer cron a moment to finish before touching indexes.
     *
     * @return bool idle now; false when it is still working after the wait
     */
    private function awaitIdleIndexer(): bool
    {
        for ($attempt = 0; $attempt < self::INDEXER_WAIT_ATTEMPTS; $attempt++) {
            if (!$this->readiness->indexerBusy()) {
                return true;
            }
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            usleep(self::INDEXER_WAIT_MICROSECONDS);
        }
        return !$this->readiness->indexerBusy();
    }

    /**
     * Load a set of products for one store and map the eligible ones.
     *
     * @param int[] $productIds
     * @param \Magento\Store\Model\Store $store
     * @param int $websiteId
     * @param string[] $codes attribute codes configured for metadata
     * @param bool $withPriceData join the price index (INNER JOIN: rows
     *        without an index entry are silently absent from the result)
     * @return array{0: array<string, array>, 1: array<string, string>, 2: int[], 3: int[]}
     *         records, variant map, ids found, ids found but ineligible
     */
    private function loadAndMap(
        array $productIds,
        $store,
        int $websiteId,
        array $codes,
        bool $withPriceData
    ): array {
        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter($productIds)
            ->setStoreId((int)$store->getId())
            ->addAttributeToSelect(array_values(array_unique(array_merge(
                ['name', 'description', 'short_description', 'price', 'special_price',
                    'special_from_date', 'special_to_date', 'image', 'url_key', 'status', 'visibility'],
                $codes
            ))));
        if ($withPriceData) {
            $collection->addPriceData();
        }
        // The gallery for MAX_IMAGES per record, loaded for the whole batch
        // in one query rather than one per product.
        $collection->addMediaGalleryData();
        $records = [];
        $variantMap = [];
        $found = [];
        $ineligible = [];
        foreach ($collection as $product) {
            /** @var Product $product */
            $found[] = (int)$product->getId();
            if (!$this->mapper->isEligible($product, $websiteId)) {
                $ineligible[] = (int)$product->getId();
                continue;
            }
            $mapped = $this->mapper->map($product, $store, $websiteId);
            $records[$mapped['record']['id']] = $mapped['record'];
            $variantMap += $mapped['variant_map'];
        }
        return [$records, $variantMap, $found, $ineligible];
    }

    /**
     * A signing failure is a credentials problem, not a delivery problem.
     *
     * A false return from openssl_pkey_get_private() means the stored private
     * key cannot be parsed - an encryption key rotated without re-encrypting, a
     * truncated column, a hand-edited config.php. Retrying cannot fix it.
     *
     * Before this guard the exception escaped CatalogClient, which propagates it
     * by design, passed straight through SyncWorker and SyncCron, and killed the
     * cron run. Magento marked the job errored and moved on; the queue kept
     * filling from ordinary product saves, nothing was ever sent, and the
     * merchant got NO signal, because the health record and the admin banner are
     * written by the code the exception had just jumped over. Catalog sync
     * simply stopped.
     *
     * Handled like the other credential-class rejections: rows stay queued with
     * their attempts untouched, health is recorded so the admin banner fires,
     * and the drain resumes once the key is fixed.
     *
     * @param int $websiteId
     * @param SignerException $e
     * @return array{ok: int, failed: int, rejected: bool}
     */
    private function signerFailure(int $websiteId, SignerException $e): array
    {
        $this->health->recordFailure($websiteId, ResponseClassifier::AUTH_ERROR);
        $this->logger->error(sprintf(
            '[quissly] catalog signing failed website=%d - batch left queued, attempts untouched: %s',
            $websiteId,
            $e->getMessage()
        ));

        return ['ok' => 0, 'failed' => 0, 'rejected' => true];
    }

    /**
     * Whether this is a state a retry cannot clear.
     *
     * No active subscription or quota (402, or 403 with a JSON detail),
     * credentials Quissly will not accept, or a server clock far enough out
     * that every signature is rejected. None of these improve by trying
     * again, and treating them as ordinary failures spends each row's three
     * attempts and then DROPS it - the queue empties, reads pending=0, and
     * looks exactly like a completed sync with nothing in Quissly.
     *
     * Clock skew belongs here for a second reason: it is how a quota refusal
     * arrives on a store whose clock has drifted, because the 403 branch
     * checks drift before it checks the body. Without it, the merchant who
     * most needs the message is the one who never gets it.
     *
     * @param string $code
     * @return bool
     */
    private function isRejection(string $code): bool
    {
        return in_array(
            $code,
            [
                ResponseClassifier::PAYMENT_REQUIRED,
                ResponseClassifier::FORBIDDEN,
                ResponseClassifier::AUTH_ERROR,
                ResponseClassifier::CLOCK_SKEW_SUSPECTED,
            ],
            true
        );
    }

    /**
     * Send one mutation batch and settle it on Quissly's answer.
     *
     * @param string $method POST|PUT
     * @param array $records Map of id => record
     * @param int $websiteId
     * @return array{ok: int, failed: int, rejected: bool}
     */
    private function dispatch(string $method, array $records, int $websiteId): array
    {
        try {
            return $this->dispatchSigned($method, $records, $websiteId);
        } catch (SignerException $e) {
            return $this->signerFailure($websiteId, $e);
        }
    }

    /**
     * The body of dispatch(); signing failures are the caller's to handle.
     *
     * Everything is decided from the answer to the send: a refusal, a
     * rate-limit, a failure (no answer, a timeout, any non-2xx) or delivered.
     *
     * @param string $method POST|PUT
     * @param array $records Map of id => record
     * @param int $websiteId
     * @return array{ok: int, failed: int, rejected: bool}
     * @throws SignerException
     */
    private function dispatchSigned(string $method, array $records, int $websiteId): array
    {
        $queuedIds = array_map('intval', array_keys($records));
        $settled = ['ok' => 0, 'failed' => 0, 'rejected' => false];
        // Counts only, never values: enough to read from the log which way the
        // stock flags went, without logging a record (2026-09-11).
        $outOfStock = 0;
        $variantsOut = 0;
        foreach ($records as $record) {
            $outOfStock += empty($record['in_stock']) ? 1 : 0;
            foreach ($record['variants'] ?? [] as $variant) {
                $variantsOut += empty($variant['in_stock']) ? 1 : 0;
            }
        }
        $this->logger->info(sprintf(
            '[quissly] sending %d records website=%d out_of_stock=%d variants_out_of_stock=%d',
            count($records),
            $websiteId,
            $outOfStock,
            $variantsOut
        ));
        $send = $this->client->sendBatch($method, $records, $websiteId === 0 ? null : $websiteId);
        if ($this->isRejection($send['code'])) {
            // Quissly refused the account, not the payload: no trial, no quota,
            // or the service switched off. Retrying cannot fix it, so burning
            // attempts would silently DELETE the queue by attrition and leave a
            // store that looks synced and holds nothing. Rows stay queued, the
            // admin is told, and the drain resumes when the account is sorted.
            $this->health->recordFailure($websiteId, $send['code']);
            $this->logger->error(sprintf(
                '[quissly] catalog sync rejected (%s) - batch left queued, attempts untouched',
                $send['code']
            ));
            return ['ok' => 0, 'failed' => 0, 'rejected' => true];
        }
        if ($send['code'] === ResponseClassifier::RATE_LIMITED) {
            // Back off: leave rows queued WITHOUT burning an attempt.
            $this->logger->info('[quissly] rate-limited - backing off, batch left queued');
            return $settled;
        }
        if ($send['code'] !== ResponseClassifier::OK) {
            // No answer, a timeout or any other status: the rows go again next run.
            $dropped = $this->queue->bumpAttempts($queuedIds, $websiteId, self::MAX_ATTEMPTS);
            $this->logDropped($dropped, $websiteId);
            return ['ok' => 0, 'failed' => count($queuedIds), 'rejected' => false];
        }
        // Accepted: clear any standing rejection so the admin banner goes away
        // once the account is sorted out. Nothing else clears this channel.
        $this->health->recordSuccess($websiteId);
        // Delivered: in Quissly now, so its next change goes as an update; off the
        // queue at the version claimed (a save during the send stays queued); and
        // the stock flags as SENT, which the stock reconcile cron compares against.
        $this->queue->markIngested($queuedIds, $websiteId);
        $this->queue->remove($queuedIds, $websiteId, $this->versionsFor($queuedIds));
        $this->snapshot->record($this->stockFlags($records), $websiteId);
        $this->logger->info(sprintf(
            '[quissly] %s accepted op=%s website=%d products=%d',
            $method,
            (string)($send['operation_id'] ?? '-'),
            $websiteId,
            count($queuedIds)
        ));
        return ['ok' => count($queuedIds), 'failed' => 0, 'rejected' => false];
    }

    /**
     * Send deletes, settle (delete is idempotent - missing ids are fine).
     *
     * @param int[] $deleteIds
     * @param int $websiteId
     * @return array{ok: int, failed: int}
     */
    private function deleteAndSettle(array $deleteIds, int $websiteId): array
    {
        try {
            return $this->deleteAndSettleSigned($deleteIds, $websiteId);
        } catch (SignerException $e) {
            return $this->signerFailure($websiteId, $e);
        }
    }

    /**
     * The body of deleteAndSettle(); signing failures are the caller's to handle.
     *
     * @param int[] $deleteIds
     * @param int $websiteId
     * @return array{ok: int, failed: int}
     * @throws SignerException
     */
    private function deleteAndSettleSigned(array $deleteIds, int $websiteId): array
    {
        $send = $this->client->sendDeletes(array_map('strval', $deleteIds), $websiteId === 0 ? null : $websiteId);
        if ($this->isRejection($send['code'])) {
            $this->health->recordFailure($websiteId, $send['code']);
            $this->logger->error(sprintf(
                '[quissly] catalog delete rejected (%s) - batch left queued, attempts untouched',
                $send['code']
            ));
            return ['ok' => 0, 'failed' => 0, 'rejected' => true];
        }
        if ($send['code'] === ResponseClassifier::RATE_LIMITED) {
            $this->logger->info('[quissly] rate-limited on delete - backing off, batch left queued');
            return ['ok' => 0, 'failed' => 0];
        }
        if ($send['code'] === ResponseClassifier::OK) {
            $this->health->recordSuccess($websiteId);
        }
        if ($send['code'] !== ResponseClassifier::OK) {
            $dropped = $this->queue->bumpAttempts($deleteIds, $websiteId, self::MAX_ATTEMPTS);
            $this->logDropped($dropped, $websiteId);
            return ['ok' => 0, 'failed' => count($deleteIds)];
        }
        // A 2xx is the delete delivered, as for every other send.
        $this->queue->clearIngested($deleteIds, $websiteId);
        $this->queue->remove($deleteIds, $websiteId, $this->versionsFor($deleteIds));
        $this->snapshot->forget($deleteIds, $websiteId);
        return ['ok' => count($deleteIds), 'failed' => 0];
    }

    /**
     * Update progress, opening the gate only on a sync that delivered.
     *
     * Partial success (some delivered, some failed) DOES open the gate: an
     * index missing a few products still serves better results than blocking
     * interception entirely, and one permanently-failing product must not be
     * able to deadlock the feature forever. The failure count stays on the
     * progress flag so the dashboard can say the sync was incomplete.
     *
     * @param int $websiteId
     * @param int $ok
     * @param int $failed
     * @param int $pendingRows Every queue row, variants included - the drain condition
     * @param int $pendingProducts Rows that are products in their own right - what a merchant is asking about
     * @return void
     */
    private function updateProgress(
        int $websiteId,
        int $ok,
        int $failed,
        int $pendingRows,
        int $pendingProducts
    ): void {
        $flag = $this->flagManager->getFlagData(self::PROGRESS_FLAG_PREFIX . $websiteId);
        $flag = is_array($flag) ? $flag : [];

        // Recorded on EVERY run, full sync or not. Products reach Quissly from
        // ordinary saves, imports and stock moves too, drained by cron - and
        // none of that was recorded anywhere, because this method used to
        // return immediately unless a full sync was flagged running. The
        // dashboard sat at 0 while the log showed batch after batch going out,
        // which is indistinguishable from a module that has stopped working.
        $flag['last_run'] = [
            'at' => time(),
            'ok' => $ok,
            'failed' => $failed,
            'pending' => $pendingProducts,
        ];

        if (!($flag['running'] ?? false)) {
            $this->flagManager->saveFlag(self::PROGRESS_FLAG_PREFIX . $websiteId, $flag);
            return;
        }
        $flag['ok'] = (int)($flag['ok'] ?? 0) + $ok;
        // Two different numbers, previously conflated into one. A batch whose
        // verdict poll is lost counts its items failed and requeues them; the
        // retry then succeeds. Adding those attempts to the same counter that
        // holds distinct successes produced ok + failed > total - a completely
        // successful sync reporting dozens of failures, which is what the guide
        // tells testers to treat as broken.
        //
        // attempts_failed is the raw tally, kept because it is a real signal
        // about the backend. failed is what a merchant is asking about: how
        // many products did not make it. Derived, so ok + failed + pending
        // always reconciles against total.
        $flag['attempts_failed'] = (int)($flag['attempts_failed'] ?? 0) + $failed;
        // Displayed against 'total', which startFullSync() measured over
        // sendable products - so it has to count the same population, or the
        // panel reports more still queued than were ever enqueued.
        $flag['pending'] = $pendingProducts;
        $total = (int)($flag['total'] ?? 0);
        $flag['failed'] = $total > 0
            ? max(0, $total - (int)$flag['ok'] - $pendingProducts)
            : 0;
        // Completion, though, is still every row: variant rows are real work
        // the drain has to get through, and the gate opens on a queue that is
        // actually empty.
        if ($pendingRows === 0) {
            $flag['running'] = false;
            $flag['finished_at'] = time();
            $delivered = (int)$flag['ok'];

            // THE gate rule: opens on completion-while-running, never on
            // trigger - AND never on a queue that merely emptied.
            //
            // bumpAttempts() removes rows once they exceed MAX_ATTEMPTS, so a
            // sync where every batch failed drains by attrition and arrives
            // here with pending === 0 looking exactly like a successful one.
            // Opening then is the worst outcome the module can produce: the
            // credentials are still valid, so once a transient backend outage
            // clears, interception goes live against an EMPTY Quissly index.
            // qsearch answers 200 with no documents, the fallback matrix
            // correctly treats that as a real answer, and every search on a
            // healthy store returns "no results". Falling back to native is
            // survivable; serving nothing is not.
            $shouldOpen = $delivered > 0 || $total === 0;

            if ($shouldOpen) {
                $this->gate->open($websiteId);
                $this->logger->info(sprintf(
                    '[quissly] full sync COMPLETE website=%d ok=%d failed=%d (attempts_failed=%d) - gate OPEN',
                    $websiteId,
                    $delivered,
                    $flag['failed'],
                    $flag['attempts_failed']
                ));
            } else {
                // Left shut deliberately; the merchant keeps native search,
                // which is the correct degradation.
                $flag['gate_blocked'] = true;
                $this->logger->error(sprintf(
                    '[quissly] full sync website=%d delivered NOTHING (ok=0 failed=%d of %d) '
                    . ' - gate stays CLOSED, native search continues',
                    $websiteId,
                    $flag['failed'],
                    $total
                ));
            }
        }
        $this->flagManager->saveFlag(self::PROGRESS_FLAG_PREFIX . $websiteId, $flag);
    }

    /**
     * Progress snapshot for CLI/admin.
     *
     * 'running' is set when a full sync starts and cleared when the queue
     * drains. A run that DIES between those two points - PHP fatal, container
     * restart, a cron worker killed - clears neither, so the flag said
     * "Running now" for ever and the dashboard polled a sync that no longer
     * existed. The lock does not cover this: run() releases it in a finally,
     * so a dead run leaves the flag set and the lock free.
     *
     * Resolved on READ rather than by writing the flag, for two reasons: a
     * dashboard visit should not mutate sync state, and if a worker does come
     * back the accounting continues from where it stopped instead of starting
     * over.
     *
     * @param int $websiteId
     * @return array|null
     */
    public function progress(int $websiteId): ?array
    {
        $flag = $this->flagManager->getFlagData(self::PROGRESS_FLAG_PREFIX . $websiteId);
        if (!is_array($flag)) {
            return null;
        }
        if (($flag['running'] ?? false) && $this->hasStalled($flag, $websiteId)) {
            $flag['running'] = false;
            $flag['stalled'] = true;
        }
        return $flag;
    }

    /**
     * Whether a sync flagged running has actually stopped.
     *
     * Two signals, and both are needed. Age alone would condemn a healthy but
     * long single run, because while one drain holds the lock every cron tick
     * stands down without writing last_run. The lock alone would condemn every
     * healthy multi-tick sync, since the lock is free between ticks. Silent
     * AND unlocked is the state no live sync can be in.
     *
     * @param array $flag
     * @param int $websiteId
     * @return bool
     */
    private function hasStalled(array $flag, int $websiteId): bool
    {
        $lastAt = (int)($flag['last_run']['at'] ?? $flag['started_at'] ?? 0);
        if ($lastAt <= 0 || (time() - $lastAt) < self::STALE_RUN_SECONDS) {
            return false;
        }
        return !$this->lockManager->isLocked(self::LOCK_PREFIX . $websiteId);
    }

    /**
     * The website's default store view (mapper context).
     *
     * @param int $websiteId
     * @return \Magento\Store\Api\Data\StoreInterface
     */
    private function defaultStoreOfWebsite(int $websiteId)
    {
        foreach ($this->storeManager->getStores() as $store) {
            if ((int)$store->getWebsiteId() === $websiteId) {
                return $store;
            }
        }
        return $this->storeManager->getDefaultStoreView();
    }

    /**
     * Log terminally dropped ids.
     *
     * @param int[] $dropped
     * @param int $websiteId
     * @return void
     */
    private function logDropped(array $dropped, int $websiteId): void
    {
        if ($dropped !== []) {
            $this->logger->warning(sprintf(
                '[quissly] DROPPED after %d attempts website=%d ids=%s',
                self::MAX_ATTEMPTS,
                $websiteId,
                implode(',', $dropped)
            ));
        }
    }
}
