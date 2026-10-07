<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Connect;

/**
 * A starting point for the workspace description on Quissly Setup, drafted from the store.
 *
 * A port of the Shopify app's `app/lib/description-draft.ts` (composeDescriptionDraft), the
 * same sentences in the same order, locked by its own test cases (DescriptionDraftTest). The
 * description becomes the Quissly organization's, and merchants facing an empty box write thin
 * ones, so Setup offers a draft they edit instead.
 *
 * Built from facts only, with no model call, so it cannot invent anything: the store's meta
 * description, how many products and of what kinds (categories), where the business is, the
 * price range and the brands. The least telling sentences are dropped first when it runs long.
 *
 * Pure and PHP 7.4-compatible. quissly-for-woocommerce and quissly-for-cs-cart carry the same
 * logic as static classes under their own names; here it is an ordinary injectable class, as
 * the Magento coding standard asks.
 */
class DescriptionDraft
{
    /** The middleware rejects organization descriptions over 500 characters. */
    public const MAX_LENGTH = 500;

    /** The draft stays well under the limit, leaving the merchant room to add. */
    private const TARGET_LENGTH = 400;
    private const MAX_META_LENGTH = 280;
    private const MAX_KINDS = 4;

    /** Store-wide categories that say nothing about what the store sells (matched on the URL key). */
    private const GENERIC = '/^(all|all-products|frontpage|home|homepage|home-page|sale|sales|on-sale|clearance'
        . '|new|new-in|new-arrivals?|latest|best-?sellers?|bestsellers|featured.*|trending|gift-?cards?'
        . '|uncategorized|default-category)$/';

    /**
     * The draft text, or null when the store gives nothing worth saying.
     *
     * @param array $input shop_name, meta_description, product_count, count_is_lower_bound,
     *     product_kinds (list of strings), collections (list of {title, handle, product_count}),
     *     location ({city, country}|null), prices ({min, max, currency}|null), vendors (list)
     * @return string|null
     */
    public function compose(array $input): ?string
    {
        $name = $this->clean($input['shop_name'] ?? null);
        $subject = $name !== '' ? $name : 'The store';
        $meta = $this->clean($input['meta_description'] ?? null);
        $usefulMeta = $this->len($meta) >= 30 && mb_strtolower($meta) !== mb_strtolower($name);

        $kinds = $this->topKinds((array)($input['product_kinds'] ?? []));
        $collections = $this->topCollections((array)($input['collections'] ?? []));
        $range = $kinds !== [] ? $kinds : $collections;
        $count = $this->countPhrase((int)($input['product_count'] ?? 0), !empty($input['count_is_lower_bound']));
        $where = $this->locationPhrase($input['location'] ?? null);

        // What it is and what it sells - the sentence the rest hangs off.
        $offers = '';
        if ($count !== '' && $range !== []) {
            $offers = 'offers ' . $count . ', including ' . $this->listOf($range);
        } elseif ($count !== '') {
            $offers = 'offers ' . $count;
        } elseif ($range !== []) {
            $offers = 'offers ' . $this->listOf($range);
        }
        $catalog = '';
        if ($where !== '' && $offers !== '') {
            $catalog = $subject . ' is based in ' . $where . ' and ' . $offers . '.';
        } elseif ($offers !== '') {
            $catalog = $subject . ' ' . $offers . '.';
        } elseif ($where !== '') {
            $catalog = $subject . ' is based in ' . $where . '.';
        }

        $priceLine = $this->pricePhrase($input['prices'] ?? null);

        $shopKey = mb_strtolower($name);
        $vendors = array_values(array_filter(
            (array)($input['vendors'] ?? []),
            function ($vendor) use ($shopKey): bool {
                return mb_strtolower($this->clean((string)$vendor)) !== $shopKey;
            }
        ));
        $brands = $this->topKinds($vendors, 3);
        $brandLine = $brands !== [] ? 'Brands include ' . $this->listOf($brands) . '.' : '';

        // Categories as extra colour, only when kinds made the catalog line and they name
        // something the kinds did not.
        $kindKeys = array_map('mb_strtolower', $kinds);
        $extra = $kinds !== []
            ? array_slice(array_values(array_filter($collections, static function (string $c) use ($kindKeys): bool {
                return !in_array(mb_strtolower($c), $kindKeys, true);
            })), 0, 3)
            : [];
        $collectionLine = $extra !== [] ? 'Collections include ' . $this->listOf($extra) . '.' : '';

        // Facts only; with none at all there is nothing honest to draft.
        if (!$usefulMeta && $catalog === '') {
            return null;
        }

        // Most telling first; the tail is dropped first when the draft runs long.
        $facts = array_values(array_filter([$catalog, $priceLine, $brandLine, $collectionLine], 'strlen'));
        $fit = function (string $metaPart) use ($facts): string {
            $kept = $facts;
            $text = $this->join($metaPart, $kept);
            while ($this->len($text) > self::TARGET_LENGTH && count($kept) > 1) {
                array_pop($kept);
                $text = $this->join($metaPart, $kept);
            }
            return $text;
        };

        $draft = $fit($usefulMeta ? $this->endSentence($this->shorten($meta, self::MAX_META_LENGTH)) : '');
        if ($this->len($draft) > self::TARGET_LENGTH && $usefulMeta) {
            // Still long: the meta description is the flexible part.
            $room = self::TARGET_LENGTH - ($catalog !== '' ? $this->len($catalog) + 1 : 0);
            $draft = $room >= 60
                ? $fit($this->endSentence($this->shorten($meta, $room - 1)))
                : $this->shorten($draft, self::TARGET_LENGTH);
        }
        if ($this->len($draft) > self::TARGET_LENGTH) {
            $draft = $this->shorten($draft, self::TARGET_LENGTH);
        }

        return mb_substr($draft, 0, self::MAX_LENGTH);
    }

    /**
     * Most common kinds first, case-insensitive, keeping the most used spelling.
     *
     * Ties keep first-seen order, as the Shopify app's stable sort does.
     *
     * @param array $kinds
     * @param int $limit
     * @return string[]
     */
    public function topKinds(array $kinds, int $limit = self::MAX_KINDS): array
    {
        $groups = [];
        foreach ($kinds as $raw) {
            $kind = $this->clean((string)$raw);
            if ($kind === '') {
                continue;
            }
            $key = mb_strtolower($kind);
            if (!isset($groups[$key])) {
                $groups[$key] = ['count' => 0, 'order' => count($groups), 'spellings' => []];
            }
            $groups[$key]['count']++;
            $groups[$key]['spellings'][$kind] = ($groups[$key]['spellings'][$kind] ?? 0) + 1;
        }
        $groups = array_values($groups);
        usort($groups, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'] ?: $a['order'] <=> $b['order'];
        });
        $out = [];
        foreach (array_slice($groups, 0, $limit) as $group) {
            $best = null;
            foreach ($group['spellings'] as $spelling => $n) {
                if ($best === null || $n > $group['spellings'][$best]) {
                    $best = (string)$spelling;
                }
            }
            $out[] = $best;
        }
        return $out;
    }

    /**
     * Categories by size, without the store-wide ones.
     *
     * Unlike Shopify's collections, category names repeat (Women > Tops, Men > Tops): one
     * name is listed once, with the counts added.
     *
     * @param array $collections
     * @return string[]
     */
    private function topCollections(array $collections): array
    {
        $kept = [];
        foreach ($collections as $i => $c) {
            $title = $this->clean((string)($c['title'] ?? ''));
            if ((int)($c['product_count'] ?? 0) > 0 && $title !== ''
                && !preg_match(self::GENERIC, mb_strtolower((string)($c['handle'] ?? '')))) {
                $key = mb_strtolower($title);
                if (!isset($kept[$key])) {
                    $kept[$key] = ['title' => $title, 'count' => 0, 'order' => $i];
                }
                $kept[$key]['count'] += (int)$c['product_count'];
            }
        }
        $kept = array_values($kept);
        usort($kept, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'] ?: $a['order'] <=> $b['order'];
        });
        return array_map(static function (array $c): string {
            return $c['title'];
        }, array_slice($kept, 0, self::MAX_KINDS));
    }

    /**
     * Collapse whitespace and trim.
     *
     * @param string|null $text
     * @return string
     */
    private function clean(?string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', (string)$text));
    }

    /**
     * Length in characters.
     *
     * @param string $text
     * @return int
     */
    private function len(string $text): int
    {
        return mb_strlen($text);
    }

    /**
     * Cut at a sentence end if one is close, otherwise at a word, never mid-word.
     *
     * @param string $text
     * @param int $max
     * @return string
     */
    private function shorten(string $text, int $max): string
    {
        if ($this->len($text) <= $max) {
            return $text;
        }
        $head = mb_substr($text, 0, $max);
        $sentenceEnd = max($this->lastPos($head, '. '), $this->lastPos($head, '! '), $this->lastPos($head, '? '));
        if ($sentenceEnd >= $max * 0.5) {
            return mb_substr($head, 0, $sentenceEnd + 1);
        }
        $wordEnd = $this->lastPos($head, ' ');
        $cut = mb_substr($head, 0, $wordEnd > 0 ? $wordEnd : $max);
        return (string)preg_replace('/[\s,;:–-]+$/u', '', $cut) . '…';
    }

    /**
     * Last position of a needle, -1 when absent.
     *
     * @param string $haystack
     * @param string $needle
     * @return int
     */
    private function lastPos(string $haystack, string $needle): int
    {
        $pos = mb_strrpos($haystack, $needle);
        return $pos === false ? -1 : $pos;
    }

    /**
     * End a sentence with a full stop unless it already has one.
     *
     * @param string $text
     * @return string
     */
    private function endSentence(string $text): string
    {
        return preg_match('/[.!?…]$/u', $text) ? $text : $text . '.';
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param string[] $items
     * @return string
     */
    private function listOf(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    /**
     * Join the meta part and the kept facts with spaces.
     *
     * @param string $metaPart
     * @param string[] $facts
     * @return string
     */
    private function join(string $metaPart, array $facts): string
    {
        return implode(' ', array_values(array_filter(array_merge([$metaPart], $facts), 'strlen')));
    }

    /**
     * A price as en-US writes it: "1,240", "9.50".
     *
     * @param float $amount
     * @return string
     */
    private function formatPrice(float $amount): string
    {
        return floor($amount) == $amount ? number_format($amount) : number_format($amount, 2);
    }

    /**
     * The price sentence.
     *
     * @param array|null $prices
     * @return string
     */
    private function pricePhrase(?array $prices): string
    {
        $max = (float)($prices['max'] ?? 0);
        if ($prices === null || $max <= 0) {
            return '';
        }
        $min = (float)($prices['min'] ?? 0);
        $cur = $this->clean((string)($prices['currency'] ?? ''));
        if ($min === $max || $min <= 0) {
            return $min === $max
                ? 'Products cost ' . $this->formatPrice($max) . ' ' . $cur . '.'
                : 'Prices go up to ' . $this->formatPrice($max) . ' ' . $cur . '.';
        }
        return 'Prices range from ' . $this->formatPrice($min) . ' to ' . $this->formatPrice($max) . ' ' . $cur . '.';
    }

    /**
     * "City, Country", or whichever is known.
     *
     * @param array|null $location
     * @return string
     */
    private function locationPhrase(?array $location): string
    {
        $city = $this->clean((string)($location['city'] ?? ''));
        $country = $this->clean((string)($location['country'] ?? ''));
        if ($city !== '' && $country !== '' && mb_strtolower($city) !== mb_strtolower($country)) {
            return $city . ', ' . $country;
        }
        return $country !== '' ? $country : $city;
    }

    /**
     * "1,240 products", "10,000+ products", "1 product".
     *
     * @param int $count
     * @param bool $lowerBound
     * @return string
     */
    private function countPhrase(int $count, bool $lowerBound): string
    {
        if ($count < 1) {
            return '';
        }
        if ($lowerBound) {
            return number_format($count) . '+ products';
        }
        return $count === 1 ? '1 product' : number_format($count) . ' products';
    }
}
