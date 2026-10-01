<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

/**
 * Splits mapped records into calls that respect two caps at once.
 *
 * The backend limits parent ids per call; the wire cap counts every record
 * that actually travels - a parent and each of its variants - because fifty
 * apparel parents at 200 variants each is 10,000 records in one POST, which
 * is too much for some merchants' upstream bandwidth. Greedy, in order, and a
 * parent is never split across calls; one larger than the wire cap on its own
 * travels alone (2026-09-10).
 */
class RecordPacker
{
    /**
     * Records keyed by product id -> a list of chunks with the same keys.
     *
     * @param array $records mapped records keyed by product id
     * @param int $maxParents most records (parents) per call
     * @param int $maxWire most records on the wire per call, variants counted
     * @return array
     */
    public function pack(array $records, int $maxParents, int $maxWire): array
    {
        $chunks = [];
        $current = [];
        $wire = 0;
        foreach ($records as $id => $record) {
            $weight = 1 + count($record['variants'] ?? []);
            if ($current !== [] && (count($current) >= $maxParents || $wire + $weight > $maxWire)) {
                $chunks[] = $current;
                $current = [];
                $wire = 0;
            }
            $current[$id] = $record;
            $wire += $weight;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }
        return $chunks;
    }
}
