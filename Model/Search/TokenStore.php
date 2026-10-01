<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Framework\App\CacheInterface;
use Quissly\Search\Model\Api\Signer;

/**
 * Single-use hand-off tokens for voice/image results.
 *
 * A proxy validates the API's returned ids, calls store() to stash them under
 * a fresh uuid with a 120 s TTL, and redirects the shopper to a results URL
 * carrying `?quissly_voice|quissly_img=<uuid>`. The interceptor then calls
 * consume() to render them; no second API call is made. Ids are re-validated on
 * the way out too (the ID boundary rule: positive integers only, never coerce
 * to 0).
 *
 * The read is REPEATABLE. It used to delete on first read, which made a token
 * unreplayable and also made the results vanish the moment a shopper did
 * anything ordinary: page 2, the back button, a reload. The question was a
 * photo or an audio clip and cannot be asked again from a URL, so the only
 * thing a second page load can hand back is the same token - and destroying it
 * turned every one of those into an empty results page.
 */
class TokenStore
{
    private const TTL_SECONDS = 120;
    private const KEY_PREFIX = 'quissly_handoff_';
    private const CACHE_TAG = 'QUISSLY_HANDOFF';

    /**
     * @param CacheInterface $cache
     * @param Signer $signer
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Signer $signer
    ) {
    }

    /**
     * Stash validated ids under a fresh token; returns the token.
     *
     * The variant map travels WITH the ids. qsearch reports top_variant_id on
     * every path, voice and image included, but the hand-off used to carry
     * only the ids - so a voice search returned the right products wearing the
     * parent's face, while the same query typed showed the matched variant's
     * picture and deep-link. The answer is computed on the proxy request and
     * rendered on a later page load, so anything the tiles need has to be in
     * the token; there is nowhere else for it to survive.
     *
     * @param int[] $ids already-validated positive integer product ids
     * @param array $variants parent product id => the variant that matched
     * @return string the uuid token to put on the results URL
     */
    public function store(array $ids, array $variants = []): string
    {
        $token = $this->signer->nonce();
        $this->cache->save(
            (string)json_encode([
                'ids' => array_values($this->sanitize($ids)),
                'variants' => $this->sanitizeVariants($variants),
            ]),
            self::KEY_PREFIX . $token,
            [self::CACHE_TAG],
            self::TTL_SECONDS
        );
        return $token;
    }

    /**
     * Read a token, repeatably for its lifetime. Returns the empty payload for
     * any miss, expiry, or malformed token - the caller then renders an empty
     * result, never an error, and never a second API call.
     *
     * @param string $token
     * @return array{ids: int[], variants: array<int, int>}
     */
    public function consume(string $token): array
    {
        $empty = ['ids' => [], 'variants' => []];
        if (!$this->isWellFormed($token)) {
            return $empty;
        }
        $raw = $this->cache->load(self::KEY_PREFIX . $token);
        if ($raw === false || $raw === null || $raw === '') {
            return $empty;
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return $empty;
        }
        // A token minted before this shape existed is a bare list of ids, and
        // one can be in flight across a deploy for its whole 120 s TTL. Read
        // it rather than handing the shopper an empty results page.
        if (!array_key_exists('ids', $decoded)) {
            return ['ids' => $this->sanitize($decoded), 'variants' => []];
        }
        return [
            'ids' => $this->sanitize((array)($decoded['ids'] ?? [])),
            'variants' => $this->sanitizeVariants((array)($decoded['variants'] ?? [])),
        ];
    }

    /**
     * Keep only well-formed parent => variant pairs (the ID boundary rule).
     *
     * Same validation the typed path applies to the very same field, and no
     * more: whether a variant really belongs to that parent is the catalogue's
     * business, and the plugins that read the hint answer it against Magento.
     *
     * @param array $variants
     * @return array<int, int>
     */
    private function sanitizeVariants(array $variants): array
    {
        $out = [];
        foreach ($variants as $parentId => $variantId) {
            $parent = $this->sanitize([$parentId]);
            $variant = $this->sanitize([$variantId]);
            if ($parent !== [] && $variant !== []) {
                $out[$parent[0]] = $variant[0];
            }
        }
        return $out;
    }

    /**
     * Keep only well-formed positive integer ids (the ID boundary rule).
     *
     * @param array $ids
     * @return int[]
     */
    private function sanitize(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id > 0) {
                $out[] = (int)$id;
            }
        }
        return $out;
    }

    /**
     * Whether a token matches the uuid shape we mint.
     *
     * A cheap guard before any cache hit, so arbitrary URL input never becomes
     * a cache key.
     *
     * @param string $token
     * @return bool
     */
    private function isWellFormed(string $token): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $token
        );
    }
}
