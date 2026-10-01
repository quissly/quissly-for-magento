<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

/**
 * Interprets a v1 catalog operation status per the R3-verified contract.
 * Pure - unit-testable without Magento.
 *
 * The three traps this class exists for (all live-verified 2026-08-17):
 *  1. Per-item statuses name VARIANT ids for configurables - the parent id we
 *     queued NEVER appears. Callers supply the variant→parent map the mapper
 *     produced at send time, and results are rolled up per parent.
 *  2. status="failed" + reason "Item skipped: no changes detected" is a benign
 *     SKIP, not a failure. Same for the add-path "already exists" (self-heal
 *     to update). n_failed alone is meaningless.
 *  3. The pending shape has no data/summary; terminal statuses are
 *     completed / partially completed / canceled.
 */
class StatusClassifier
{
    /**
     * Explicit terminal-state ALLOWLIST. The deployed pipeline emits interim
     * states we must not enumerate exhaustively ("pending", "started", ...) -
     * live-caught 2026-08-17 when "started" slipped a blocklist check and an
     * entire sync classified as failed. Unknown state = still processing.
     */
    public const TERMINAL_STATES = ['completed', 'partially completed', 'canceled', 'failed'];

    public const ITEM_OK = 'ok';
    public const ITEM_ALREADY_EXISTS = 'already_exists';
    public const ITEM_NOT_FOUND = 'not_found';
    public const ITEM_FAILED = 'failed';

    /**
     * Whether a decoded status body is terminal.
     *
     * @param array $status Decoded status body
     * @return bool
     */
    public function isTerminal(array $status): bool
    {
        return in_array(strtolower((string)($status['status'] ?? '')), self::TERMINAL_STATES, true);
    }

    /**
     * Whether a terminal operation was cancelled outright.
     *
     * Cancellation fails loudly: callers retry the whole batch.
     *
     * @param array $status
     * @return bool
     */
    public function isCancelled(array $status): bool
    {
        return strtolower((string)($status['status'] ?? '')) === 'canceled';
    }

    /**
     * Roll per-item results up to the QUEUED entity ids.
     *
     * Parents for configurables, the id itself for simples.
     *
     * @param array $status Decoded terminal status body
     * @param array $variantToQueued Wire id => queued id from the mapper
     *        (ids absent from the map are reported under themselves)
     * @return array Queued id => ITEM_* constant (worst wins)
     */
    public function rollUp(array $status, array $variantToQueued): array
    {
        $results = [];
        foreach ((array)($status['data'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $wireId = (string)($item['q_external_id'] ?? '');
            if ($wireId === '') {
                continue;
            }
            $queuedId = $variantToQueued[$wireId] ?? $wireId;
            $verdict = $this->classifyItem(
                (string)($item['status'] ?? ''),
                (string)($item['reason'] ?? '')
            );
            $results[$queuedId] = $this->worst($results[$queuedId] ?? null, $verdict);
        }
        return $results;
    }

    /**
     * Classify one per-item entry.
     *
     * @param string $status
     * @param string $reason
     * @return string ITEM_* constant
     */
    public function classifyItem(string $status, string $reason): string
    {
        $reasonLower = strtolower($reason);
        if (str_contains($reasonLower, 'no changes detected')) {
            return self::ITEM_OK; // benign skip reported as "failed" (R3)
        }
        if (str_contains($reasonLower, 'already exists')) {
            return self::ITEM_ALREADY_EXISTS; // add-path duplicate → route to update
        }
        if (str_contains($reasonLower, "doesn't exist") || str_contains($reasonLower, 'does not exist')) {
            return self::ITEM_NOT_FOUND; // update-path ghost → route back to add
        }
        if (strtolower($status) === 'successful') {
            return self::ITEM_OK;
        }
        return self::ITEM_FAILED;
    }

    /**
     * Merge two verdicts for the same queued entity.
     *
     * Any real failure wins; already_exists outranks ok (it demands the
     * re-route side effect).
     *
     * @param string|null $current
     * @param string $incoming
     * @return string
     */
    private function worst(?string $current, string $incoming): string
    {
        $rank = [
            self::ITEM_OK => 0,
            self::ITEM_ALREADY_EXISTS => 1,
            self::ITEM_NOT_FOUND => 2,
            self::ITEM_FAILED => 3,
        ];
        if ($current === null || $rank[$incoming] > $rank[$current]) {
            return $incoming;
        }
        return $current;
    }
}
