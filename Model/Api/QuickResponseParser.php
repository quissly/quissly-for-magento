<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

/**
 * Reads the ordered product ids out of a /v2beta/quick response.
 *
 * Contract: the body is a bare JSON ARRAY. Quick
 * used to return renderable metadata alongside each id; as of 2026-09-01 it
 * returns ids and scores with `metadata` empty:
 *
 *   [{"id": 41720, "score": 30.7, "metadata": {}}, …]
 *
 * So this parses ids only, and SuggestionHydrator loads the rest from Magento.
 * That is the better arrangement regardless of what Quick sends: names, prices
 * and images then come from the shopper's own store view, at the theme's image
 * sizes, in their language and currency - none of which Quissly's copy could
 * guarantee, since it is written at catalog-sync time and drifts.
 *
 * ORDER IS THE PAYLOAD. Quick's ranking is the only thing this endpoint
 * actually decides, so the ids come back in the order received and the
 * hydrator must not re-sort them.
 */
class QuickResponseParser
{
    /** Nothing sensible renders past this; a runaway response is a bug upstream. */
    private const MAX_SUGGESTIONS = 12;

    /**
     * Parse a Quick response into ordered Magento product ids.
     *
     * @param string $body Raw response body
     * @return array<int, int>
     */
    public function parse(string $body): array
    {
        $decoded = json_decode($body, true);
        // A bare array is the contract. An object means the shape changed
        // upstream, and guessing at it would be worse than showing nothing.
        if (!is_array($decoded) || array_is_list($decoded) === false) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = $this->platformId($entry);
            if ($id !== null && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $ids;
    }

    /**
     * The Magento product id, from whichever field carries one.
     *
     * The top-level `id` is Quissly's internal uuid and
     * the platform id arrives as metadata.q_external_id. That holds only when
     * the tenant's quick config lists q_external_id in out_fields; live
     * responses also carry a plain integer id at the top level, which IS the
     * platform id.
     *
     * A uuid is never usable as a Magento product id, and passing one on would
     * send shoppers to a product that does not exist - so anything that is not
     * a positive integer is dropped rather than guessed at.
     *
     * @param array $entry
     * @return int|null
     */
    private function platformId(array $entry): ?int
    {
        $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];

        foreach ([$metadata['q_external_id'] ?? null, $entry['id'] ?? null] as $candidate) {
            if (is_int($candidate) && $candidate > 0) {
                return $candidate;
            }
            if (is_string($candidate) && ctype_digit(trim($candidate)) && (int)$candidate > 0) {
                return (int)trim($candidate);
            }
        }

        return null;
    }
}
