<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Cart;

/**
 * Quissly's own product ids, as the chat widget uses them: uuid5 of the
 * qsearch service's quissly_service_link and our product id. Stateless.
 *
 * The widget's generic cart (stores without a dedicated widget build) names
 * products this way in its `quissly:generic-cart-sync` event, never by Magento
 * id - so the chat cart bridge translates them back.
 */
class ChatIds
{
    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    public const MAX_IDS = 50;

    /**
     * RFC 4122 version 5 (SHA-1).
     *
     * @param string $namespace
     * @param string $name
     * @return string
     */
    public function uuid5(string $namespace, string $name): string
    {
        $hash = sha1((string)hex2bin(str_replace('-', '', strtolower($namespace))) . $name);

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12)
        );
    }

    /**
     * Quissly id => product id.
     *
     * @param string $namespace
     * @param int[] $productIds
     * @return array<string, int>
     */
    public function buildMap(string $namespace, array $productIds): array
    {
        $map = [];
        foreach ($productIds as $id) {
            $map[$this->uuid5($namespace, (string)(int)$id)] = (int)$id;
        }
        return $map;
    }

    /**
     * Well-formed ids, lower-cased and de-duplicated, at most MAX_IDS.
     *
     * @param string[] $raw
     * @return string[]
     */
    public function validIds(array $raw): array
    {
        $ids = [];
        foreach ($raw as $id) {
            $id = strtolower(trim((string)$id));
            if (preg_match(self::UUID_PATTERN, $id) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return array_slice($ids, 0, self::MAX_IDS);
    }

    /**
     * The known ones of $ids, translated; unknown ids are left out, never guessed.
     *
     * @param string[] $ids
     * @param int[] $map Quissly id => product id
     * @return array<string, int>
     */
    public function resolve(array $ids, array $map): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (isset($map[$id])) {
                $out[$id] = $map[$id];
            }
        }
        return $out;
    }
}
