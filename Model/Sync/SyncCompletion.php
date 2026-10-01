<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

/**
 * Whether the first catalog sync has FINISHED for a website.
 *
 * Distinct from the first-sync gate, deliberately. The gate opens as soon as a
 * sync delivers anything (`$delivered > 0`), because interception against a
 * partly-filled index still returns real products and a store must not be
 * locked out of its own feature by one unmappable item.
 *
 * Switching a feature ON is a stricter question: the merchant is deciding that
 * their catalog IS in Quissly. Doing that at 40 of 178 means shoppers search a
 * catalog that is missing two thirds of the shop, with nothing reporting a
 * fault - so this asks for the whole run to be done, not merely started.
 */
class SyncCompletion
{
    /**
     * @param QueueResource $queue
     * @param SyncWorker $worker
     * @param FirstSyncGate $gate
     */
    public function __construct(
        private readonly QueueResource $queue,
        private readonly SyncWorker $worker,
        private readonly FirstSyncGate $gate
    ) {
    }

    /**
     * Whether the first catalog sync has run to completion for this website.
     *
     * @param int $websiteId
     * @return bool
     */
    public function isComplete(int $websiteId): bool
    {
        // The gate is necessary but not sufficient: it says a sync reached
        // Quissly at all, which is the floor rather than the finish line.
        if (!$this->gate->isOpen($websiteId)) {
            return false;
        }

        $progress = $this->worker->progress($websiteId);
        if ($progress === null) {
            // No record of a run. The gate can be open on a store whose queue
            // was empty from the start, and an empty catalog is a legitimate
            // state - the gate already decided that.
            return true;
        }

        if (!empty($progress['running'])) {
            return false;
        }

        // Settled means every product the FIRST run queued got an answer.
        // Deliberately not "the queue is empty": ordinary edits queue products
        // forever afterwards, and re-blocking the toggle every time someone
        // saves a product would disable a working feature over routine
        // traffic. The first run finishing is the question; what happens after
        // it is reconciliation's job, not this guard's.
        //
        // Failures count as settled. The run ended, some items could not be
        // mapped, and waiting for zero would block the merchant forever on one
        // broken product.
        $total = (int)($progress['total'] ?? 0);
        $settled = (int)($progress['ok'] ?? 0) + (int)($progress['failed'] ?? 0);

        return $total === 0 || $settled >= $total;
    }

    /**
     * How far along the run is, for a message the merchant can act on.
     *
     * @param int $websiteId
     * @return array{ok: int, total: int, pending: int}
     */
    public function progressSummary(int $websiteId): array
    {
        $progress = $this->worker->progress($websiteId) ?? [];

        return [
            'ok' => (int)($progress['ok'] ?? 0),
            'total' => (int)($progress['total'] ?? 0),
            'pending' => $this->queue->countPending($websiteId),
        ];
    }
}
