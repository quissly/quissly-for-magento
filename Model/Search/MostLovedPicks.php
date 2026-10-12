<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

/**
 * Most loved products' rules (Model/Search/MostLoved, the Quissly Shopify app's): which 8 picked
 * products the overlay shows, what Configuration warns about, and how a SKU list is read. Pure.
 */
class MostLovedPicks
{
    /** Products shown, and backups for the ones that cannot be bought. */
    public const SHOWN = 8;
    public const BACKUPS = 4;

    /**
     * SKUs as typed: one per line, trimmed, each once, in order, at most $max.
     *
     * @param string $raw
     * @param int $max
     * @return string[]
     */
    public function skus(string $raw, int $max): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $sku = trim($line);
            if ($sku !== '' && !in_array($sku, $out, true)) {
                $out[] = $sku;
            }
        }
        return array_slice($out, 0, $max);
    }

    /**
     * The 8 to show, in order, or none.
     *
     * @param string[] $shown
     * @param string[] $backups
     * @param callable $available fn(string $sku): bool - can be bought now
     * @return string[]
     */
    public function pick(array $shown, array $backups, callable $available): array
    {
        if (count($shown) !== self::SHOWN || count($backups) !== self::BACKUPS
            || $this->duplicates($shown, $backups) !== []
        ) {
            return [];
        }
        $out = [];
        foreach (array_merge($shown, $backups) as $sku) {
            if (count($out) < self::SHOWN && !in_array($sku, $out, true) && $available($sku)) {
                $out[] = $sku;
            }
        }
        return count($out) === self::SHOWN ? $out : [];
    }

    /**
     * What the merchant has to fix, or null when nothing.
     *
     * Picks still to make, products picked in both lists, and picks that cannot be shown, with why.
     *
     * @param string[] $shown
     * @param string[] $backups
     * @param callable $state fn(string $sku): array{title:string, reason:string} - reason '' when
     *                        it can be shown, else not_found | not_visible | out_of_stock
     * @return array|null missing_shown, missing_backups, duplicates (titles), problems, hidden
     */
    public function problems(array $shown, array $backups, callable $state): ?array
    {
        $problems = [];
        $ok = [];
        foreach ([[$shown, false], [$backups, true]] as [$skus, $backup]) {
            foreach ($skus as $sku) {
                $product = $state($sku);
                if ($product['reason'] === '') {
                    $ok[$sku] = true;
                } else {
                    $problems[] = ['title' => $product['title'], 'backup' => $backup, 'reason' => $product['reason']];
                }
            }
        }
        $duplicates = [];
        foreach ($this->duplicates($shown, $backups) as $sku) {
            $duplicates[] = $state($sku)['title'];
        }
        $missingShown = max(0, self::SHOWN - count($shown));
        $missingBackups = max(0, self::BACKUPS - count($backups));
        if ($missingShown === 0 && $missingBackups === 0 && $duplicates === [] && $problems === []) {
            return null;
        }
        return [
            'missing_shown' => $missingShown,
            'missing_backups' => $missingBackups,
            'duplicates' => $duplicates,
            'problems' => $problems,
            'hidden' => $missingShown > 0 || $missingBackups > 0 || $duplicates !== [] || count($ok) < self::SHOWN,
        ];
    }

    /**
     * The backups that are also picked to show.
     *
     * Each product goes in one list only (the Shopify app's rule): nothing is shown until it is fixed.
     *
     * @param string[] $shown
     * @param string[] $backups
     * @return string[]
     */
    public function duplicates(array $shown, array $backups): array
    {
        return array_values(array_filter($backups, fn (string $sku): bool => in_array($sku, $shown, true)));
    }
}
