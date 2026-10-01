<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

/**
 * Parses a qsearch 200 body per the ID-boundary rule:
 * `documents[].id` arrives as a STRING; each is validated as a well-formed
 * positive integer and cast; malformed/zero/negative ids are DROPPED (callers
 * log the drop count) - never coerced to 0. Pure - unit-testable without Magento.
 */
class QsearchResponseParser
{
    /**
     * Parse a qsearch response body.
     *
     * @param string $body Raw JSON body of an HTTP 200 response
     * @return array{ids: int[], total: int, dropped: int, malformed: bool, variants: array<int, int>}
     */
    public function parse(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !array_key_exists('documents', $decoded)) {
            return ['ids' => [], 'total' => 0, 'dropped' => 0, 'malformed' => true, 'variants' => []];
        }
        $ids = [];
        $dropped = 0;
        $variants = [];
        foreach ((array)$decoded['documents'] as $document) {
            $document = is_array($document) ? $document : [];
            $id = $this->platformId($document);
            if ($id === null) {
                $dropped++;
                continue;
            }
            $ids[] = $id;
            // Which child of a configurable actually matched. Live-confirmed
            // 2026-09-08: searching "red tee" answers
            // {"id":"58","score":0.91,"top_variant_id":"56"} - 58 is the parent
            // Magento renders, 56 is the red child the shopper meant. Top level,
            // not inside metadata. Absent for simple products.
            $variant = $this->validateId($document['top_variant_id'] ?? null);
            if ($variant !== null && $variant !== $id) {
                $variants[$id] = $variant;
            }
        }
        $total = $decoded['num_total_results'] ?? 0;
        $total = is_numeric($total) ? max(0, (int)$total) : 0;

        return [
            'ids' => $ids,
            'total' => $total,
            'dropped' => $dropped,
            'malformed' => false,
            'variants' => $variants,
        ];
    }

    /**
     * The Magento product id, from whichever field carries one.
     *
     * Text search answers with a plain integer id at the top level, so that is
     * the usual source. qimage does NOT: its documents are keyed by Quissly's
     * internal Qdrant point uuid, and the platform id arrives as
     * metadata.q_external_id - the same split QuickResponseParser already
     * handles. Reading only the top-level id made every image search return
     * nothing: the match came back scored 0.995 and was then dropped here as a
     * malformed id, which looked identical to "no results".
     *
     * A uuid is never usable as a Magento product id - passing one on would
     * send shoppers to a product that does not exist - so anything that is not
     * a positive integer is still dropped rather than guessed at.
     *
     * @param array $document
     * @return int|null
     */
    private function platformId(array $document): ?int
    {
        $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : [];

        foreach ([$metadata['q_external_id'] ?? null, $document['id'] ?? null] as $candidate) {
            $id = $this->validateId($candidate);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Validate one wire id as a positive integer; null when invalid.
     *
     * @param mixed $raw
     * @return int|null
     */
    private function validateId($raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        if (is_string($raw) && preg_match('/^[1-9][0-9]*$/', $raw) === 1) {
            return (int)$raw;
        }
        return null;
    }
}
