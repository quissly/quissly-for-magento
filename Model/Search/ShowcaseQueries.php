<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

/**
 * Showcase queries: example searches built from the store's own catalog - the search bar
 * suggestions the overlay types when the merchant has written none (port of the Quissly
 * Shopify app's: same rules, same output; the WooCommerce and CS-Cart plugins have the
 * same port). Each demonstrates a different ability (several
 * conditions at once, a price limit, a misspelling, a brand, a gift idea...), so the five
 * read as a tour. Built from catalog facts - no model call - and every candidate is RUN
 * through search before it is kept (ShowcaseRunner).
 *
 * Pure: unit-tested against the Shopify app's own expectations.
 *
 * Phrased per language (PHRASES: en, ka, fr, ru - 2026-10-06, a list per store-view language):
 * a language given is used; with none, Georgian when most titles are Georgian, else English.
 *
 * Catalog facts: array{shop_name:?string, currency:?string, products: list<array{title,
 * product_type:?string, category:?string, vendor:?string, options: list<array{name, values:
 * string[]}>, min_price:?float}>}.
 */
class ShowcaseQueries
{
    /** How many queries end up in the list. */
    public const QUERY_COUNT = 5;
    /** Search calls spent validating candidates, per attempt. */
    public const MAX_VALIDATION_SEARCHES = 12;
    /** A query must return at least this many products to be worth showing. */
    public const MIN_RESULTS = 2;

    private const COLOR_OPTION    = '/^(colou?r|couleur|ფერი|цвет)$/iu';
    private const SIZE_OPTION     = '/^(size|taille|ზომა|размер)$/iu';
    private const MATERIAL_OPTION = '/^(material|fabric|matière|matériau|tissu|მასალა|материал)$/iu';

    /**
     * How each kind is phrased, per language ({type} {color} {size} {material} {n} {cur}).
     * Word order follows the language: French puts the colour and the material after the type.
     */
    public const PHRASES = [
        'en' => [
            'attributes' => '{color} {type} size {size}', 'price' => '{type} under {n} {cur}',
            'cheapest' => 'cheapest {type}', 'material' => '{material} {type}', 'color' => '{color} {type}',
            'gift' => 'gift ideas under {n} {cur}',
        ],
        'ka' => [
            'attributes' => '{color} {type} ზომა {size}', 'price' => '{type} {n} {cur}-მდე',
            'cheapest' => 'ყველაზე იაფი {type}', 'material' => '{material} {type}', 'color' => '{color} {type}',
            'gift' => 'საჩუქარი {n} {cur}-მდე',
        ],
        'fr' => [
            'attributes' => '{type} {color} taille {size}', 'price' => '{type} à moins de {n} {cur}',
            'cheapest' => '{type} pas cher', 'material' => '{type} en {material}', 'color' => '{type} {color}',
            'gift' => 'idée cadeau à moins de {n} {cur}',
        ],
        'ru' => [
            'attributes' => '{color} {type} размер {size}', 'price' => '{type} до {n} {cur}',
            'cheapest' => 'самые дешёвые {type}', 'material' => '{material} {type}', 'color' => '{color} {type}',
            'gift' => 'подарок до {n} {cur}',
        ],
    ];

    /** Kinds, in the order candidates are interleaved. */
    private const ORDER = [
        'attributes', 'price_limit', 'misspelling', 'transliteration', 'brand', 'gift', 'cheapest', 'material', 'color',
    ];

    private const GEORGIAN_TO_LATIN = [
        'ა' => 'a', 'ბ' => 'b', 'გ' => 'g', 'დ' => 'd', 'ე' => 'e', 'ვ' => 'v', 'ზ' => 'z', 'თ' => 't',
        'ი' => 'i', 'კ' => 'k', 'ლ' => 'l', 'მ' => 'm', 'ნ' => 'n', 'ო' => 'o', 'პ' => 'p', 'ჟ' => 'zh',
        'რ' => 'r', 'ს' => 's', 'ტ' => 't', 'უ' => 'u', 'ფ' => 'p', 'ქ' => 'k', 'ღ' => 'gh', 'ყ' => 'q',
        'შ' => 'sh', 'ჩ' => 'ch', 'ც' => 'ts', 'ძ' => 'dz', 'წ' => 'ts', 'ჭ' => 'ch', 'ხ' => 'kh',
        'ჯ' => 'j', 'ჰ' => 'h',
    ];

    /**
     * Candidate queries in order of preference, several per kind.
     *
     * The runner runs them through search and keeps the first that return results, one per kind.
     *
     * @param array $facts Catalog facts (see the class comment).
     * @param string|null $language a PHRASES language; null = Georgian or English, from the titles
     * @return array<int,array{kind:string,query:string}>
     */
    public function buildCandidates(array $facts, ?string $language = null)
    {
        $products = array_values(
            array_filter(
                (array) ($facts['products'] ?? []),
                function ($p) {
                    return '' !== $this->clean($p['title'] ?? '');
                }
            )
        );
        if (empty($products)) {
            return [];
        }

        $georgianCount = 0;
        foreach ($products as $p) {
            $georgianCount += $this->isGeorgian((string) $p['title']) ? 1 : 0;
        }
        if ($language === null || !isset(self::PHRASES[$language])) {
            $language = $georgianCount > count($products) / 2 ? 'ka' : 'en';
        }
        $georgian = $language === 'ka';
        $say = static function (string $kind, array $words) use ($language): string {
            return strtr(self::PHRASES[$language][$kind], $words);
        };
        $currency = $this->clean($facts['currency'] ?? '');
        $shopKey = mb_strtolower($this->clean($facts['shop_name'] ?? ''));
        $kindOf  = function ($p) {
            $type = $this->clean($p['product_type'] ?? '');
            return '' !== $type ? $type : $this->clean($p['category'] ?? '');
        };

        $types = array_slice($this->ranked(array_map($kindOf, $products)), 0, 4);
        $out   = [];
        $add   = function ($kind, $query) use (&$out) {
            $q = $this->clean($query);
            if ('' === $q || mb_strlen($q) > 80) {
                return;
            }
            foreach ($out as $c) {
                if (mb_strtolower($c['query']) === mb_strtolower($q)) {
                    return;
                }
            }
            $out[] = ['kind' => $kind, 'query' => $q];
        };

        foreach ($types as $type) {
            $ofType = array_values(
                array_filter(
                    $products,
                    function ($p) use ($kindOf, $type) {
                        return mb_strtolower($kindOf($p)) === mb_strtolower($type);
                    }
                )
            );
            $typeText  = $this->lowerLatin($type);
            $colors    = $this->optionValues($ofType, self::COLOR_OPTION);
            $sizes     = $this->optionValues($ofType, self::SIZE_OPTION);
            $materials = $this->optionValues($ofType, self::MATERIAL_OPTION);

            // Several conditions at once: colour + type + size.
            if (isset($colors[0], $sizes[0])) {
                $add('attributes', $say('attributes', [
                    '{color}' => $this->lowerLatin($colors[0]), '{type}' => $typeText, '{size}' => $sizes[0],
                ]));
            }
            // A price limit a real shopper would pick: just above the typical price.
            $typical = $this->median(
                array_map(
                    function ($p) {
                        return (float) ($p['min_price'] ?? 0);
                    },
                    $ofType
                )
            );
            if ($typical && '' !== $currency) {
                $add('price_limit', $say('price', [
                    '{type}' => $typeText, '{n}' => (string)$this->niceCeil($typical), '{cur}' => $currency,
                ]));
            }

            if ($georgian) {
                $add('transliteration', $this->transliterateGeorgian($typeText));
            } else {
                $add('misspelling', $this->misspell($typeText));
            }

            $brands = array_values(
                array_filter(
                    $this->ranked(
                        array_map(
                            function ($p) {
                                return (string) ($p['vendor'] ?? '');
                            },
                            $ofType
                        )
                    ),
                    function ($v) use ($shopKey) {
                        return mb_strtolower($v) !== $shopKey;
                    }
                )
            );
            if (isset($brands[0])) {
                $add('brand', "{$brands[0]} {$typeText}");
            }

            $add('cheapest', $say('cheapest', ['{type}' => $typeText]));
            if (isset($materials[0])) {
                $add('material', $say('material', [
                    '{material}' => $this->lowerLatin($materials[0]), '{type}' => $typeText,
                ]));
            }
            if (isset($colors[0])) {
                $add('color', $say('color', ['{color}' => $this->lowerLatin($colors[0]), '{type}' => $typeText]));
            }
        }

        // A gift idea, priced from the whole catalog.
        $typicalAll = $this->median(
            array_map(
                function ($p) {
                    return (float) ($p['min_price'] ?? 0);
                },
                $products
            )
        );
        if ($typicalAll && '' !== $currency) {
            $n = $this->niceCeil($typicalAll);
            $add('gift', $say('gift', ['{n}' => (string)$n, '{cur}' => $currency]));
        }

        // Interleave kinds so the first few candidates already cover different abilities
        // (a stable sort: by kind order, then by the order they were built in).
        $indexed = [];
        foreach ($out as $i => $c) {
            $indexed[] = [array_search($c['kind'], self::ORDER, true), $i, $c];
        }
        usort(
            $indexed,
            function ($a, $b) {
                return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
            }
        );

        return array_map(
            function ($x) {
                return $x[2];
            },
            $indexed
        );
    }

    /**
     * The values of the options whose name matches $re (colour, size, material), commonest first.
     *
     * @param array $products Products of one type.
     * @param string $re Option-name pattern.
     * @return string[]
     */
    private function optionValues(array $products, $re)
    {
        $values = [];
        foreach ($products as $p) {
            foreach ((array) ($p['options'] ?? []) as $o) {
                if (!preg_match($re, $this->clean($o['name'] ?? ''))) {
                    continue;
                }
                foreach ((array) ($o['values'] ?? []) as $v) {
                    $values[] = (string) $v;
                }
            }
        }
        return $this->ranked($values);
    }

    /**
     * Keep up to QUERY_COUNT validated queries, one per kind first.
     *
     * Only if kinds run out does a second query of the same kind get in.
     *
     * @param array $validated Validated candidates, in order.
     * @param int   $count     How many.
     * @return string[]
     */
    public function pick(array $validated, $count = self::QUERY_COUNT)
    {
        $picked = [];
        foreach ($validated as $i => $c) {
            if (count($picked) >= $count) {
                break;
            }
            $kinds = array_column($picked, 'kind');
            if (!in_array($c['kind'], $kinds, true)) {
                $picked[$i] = $c;
            }
        }
        foreach ($validated as $i => $c) {
            if (count($picked) >= $count) {
                break;
            }
            if (!isset($picked[$i])) {
                $picked[$i] = $c;
            }
        }
        // Keep the order they were picked in (first pass, then second).
        return array_values(array_column($picked, 'query'));
    }

    /**
     * Run candidates through search; keep those with at least MIN_RESULTS.
     *
     * One per kind first, so the call budget is not spent on five variants of the same idea.
     *
     * @param array    $candidates Candidates (buildCandidates()).
     * @param callable $search     query => number of results (may throw).
     * @return array<int,array{kind:string,query:string}>
     */
    public function validate(array $candidates, callable $search)
    {
        $accepted = [];
        $tried    = [];
        $calls    = 0;

        $attempt = function ($i, $c) use (&$accepted, &$tried, &$calls, $search) {
            $tried[$i] = true;
            ++$calls;
            try {
                if ((int) $search($c['query']) >= self::MIN_RESULTS) {
                    $accepted[] = $c;
                }
            } catch (\Throwable $e) {
                // A failed search is just a candidate not kept.
                unset($e);
            }
        };
        $done         = function () use (&$calls, &$accepted) {
            return $calls >= self::MAX_VALIDATION_SEARCHES || count($accepted) >= self::QUERY_COUNT;
        };
        $hasAccepted = function ($kind) use (&$accepted) {
            return in_array($kind, array_column($accepted, 'kind'), true);
        };

        // 1. The first candidate of every kind, so a failing kind cannot use up the budget
        //    with its second and third variants before other kinds are tried.
        $firstOfKind = [];
        foreach ($candidates as $i => $c) {
            if ($done()) {
                break;
            }
            if (isset($firstOfKind[$c['kind']])) {
                continue;
            }
            $firstOfKind[$c['kind']] = true;
            $attempt($i, $c);
        }
        // 2. Second choices, only for kinds that have nothing yet.
        foreach ($candidates as $i => $c) {
            if ($done()) {
                break;
            }
            if (!isset($tried[$i]) && !$hasAccepted($c['kind'])) {
                $attempt($i, $c);
            }
        }
        // 3. Still short: anything left, even a second query of the same kind.
        foreach ($candidates as $i => $c) {
            if ($done()) {
                break;
            }
            if (!isset($tried[$i])) {
                $attempt($i, $c);
            }
        }

        return $accepted;
    }

    /**
     * A round number just above $x: 37 -> 40, 143 -> 150, 1830 -> 1900.
     *
     * @param float $x Number.
     * @return int
     */
    public function niceCeil($x)
    {
        if (!($x > 0)) {
            return 0;
        }
        $step = $x < 20 ? 5 : ($x < 100 ? 10 : ($x < 500 ? 50 : ($x < 2000 ? 100 : 500)));

        return (int) (ceil($x / $step) * $step);
    }

    /**
     * A believable typo: swap two adjacent letters in the middle of the longest Latin word.
     * "hoodies" -> "hooides". Null when there is no word to misspell.
     *
     * @param string $text Text.
     * @return string|null
     */
    public function misspell($text)
    {
        $words = preg_split('/\s+/u', (string) $text);
        $best  = -1;
        foreach ($words as $i => $w) {
            if (preg_match('/^[A-Za-z]{5,}$/', $w) && ($best < 0 || strlen($w) > strlen($words[$best]))) {
                $best = $i;
            }
        }
        if ($best < 0) {
            return null;
        }
        $w    = $words[$best];
        $i    = (int) floor(strlen($w) / 2);
        $typo = substr($w, 0, $i) . $w[$i + 1] . $w[$i] . substr($w, $i + 2);
        if (strtolower($typo) === strtolower($w)) {
            return null;
        }
        $words[$best] = $typo;

        return implode(' ', $words);
    }

    /**
     * Georgian typed in Latin letters, the way many shoppers search: "მაისური" -> "maisuri".
     *
     * Null when there is nothing Georgian to convert.
     *
     * @param string $text Text.
     * @return string|null
     */
    public function transliterateGeorgian($text)
    {
        if (!$this->isGeorgian((string) $text)) {
            return null;
        }

        return strtr((string) $text, self::GEORGIAN_TO_LATIN);
    }

    /**
     * Whether the text has Georgian letters.
     *
     * @param string $text Text.
     * @return bool
     */
    private function isGeorgian($text)
    {
        return (bool) preg_match('/[\x{10A0}-\x{10FF}]/u', (string) $text);
    }

    /**
     * Whitespace collapsed, trimmed.
     *
     * @param mixed $text Text.
     * @return string
     */
    private function clean($text)
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * Lowercase for Latin text; other scripts (and their caps) left alone.
     *
     * @param string $text Text.
     * @return string
     */
    private function lowerLatin($text)
    {
        return preg_match('/[A-Za-z]/', (string) $text) ? mb_strtolower((string) $text) : (string) $text;
    }

    /**
     * Most common values first, case-insensitive, keeping the commonest spelling.
     *
     * Ties keep first-seen order.
     *
     * @param string[] $values Values.
     * @return string[]
     */
    private function ranked(array $values)
    {
        $groups = [];
        $order  = 0;
        foreach ($values as $raw) {
            $v = $this->clean($raw);
            if ('' === $v) {
                continue;
            }
            $key = mb_strtolower($v);
            if (!isset($groups[$key])) {
                $groups[$key] = ['count' => 0, 'first' => $order++, 'spellings' => []];
            }
            ++$groups[$key]['count'];
            if (!isset($groups[$key]['spellings'][$v])) {
                $groups[$key]['spellings'][$v] = [0, count($groups[$key]['spellings'])];
            }
            ++$groups[$key]['spellings'][$v][0];
        }
        $groups = array_values($groups);
        usort(
            $groups,
            function ($a, $b) {
                return $b['count'] <=> $a['count'] ?: $a['first'] <=> $b['first'];
            }
        );

        return array_map(
            function ($g) {
                $spellings = $g['spellings'];
                uasort(
                    $spellings,
                    function ($a, $b) {
                        return $b[0] <=> $a[0] ?: $a[1] <=> $b[1];
                    }
                );
                return (string) array_key_first($spellings);
            },
            $groups
        );
    }

    /**
     * Median of the positive values, or null.
     *
     * @param float[] $xs Values.
     * @return float|null
     */
    private function median(array $xs)
    {
        $s = array_values(
            array_filter(
                $xs,
                function ($x) {
                    return $x > 0;
                }
            )
        );
        if (empty($s)) {
            return null;
        }
        sort($s);
        $mid = (int) floor(count($s) / 2);

        return count($s) % 2 ? $s[$mid] : ($s[$mid - 1] + $s[$mid]) / 2;
    }
}
