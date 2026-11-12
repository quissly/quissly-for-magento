<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

/**
 * Per-IP rate limit for the anonymous voice/image proxies.
 *
 * The third protection required alongside the toggle gate
 * and the body cap. It matters more here than on a typical endpoint: these
 * routes are unauthenticated and CSRF-exempt by design, and every accepted
 * request costs the MERCHANT money - each one triggers a server-side Gemini
 * transcription or image embedding. Without a limit, an anonymous caller can
 * run up a bill on a store that has done nothing wrong.
 *
 * The IP is hashed before it becomes a cache key: a rate limiter has no reason
 * to persist a raw address, and cache backends are shared infrastructure.
 *
 * The counter is read-modify-write rather than atomic, so under heavy
 * concurrency a few extra requests can slip through. That is the right
 * trade-off for a cost guard - it bounds abuse by orders of magnitude, and
 * being exactly right at the boundary buys nothing.
 */
class MediaThrottle
{
    /** Requests permitted per IP, per media kind, per window. */
    public const LIMIT_PER_WINDOW = 12;

    /** Window length in seconds. */
    public const WINDOW_SECONDS = 60;

    /** Per kind: [requests, window seconds], for the whole store (allowStore()). */
    public const STORE_LIMITS = [
        'events' => [1200, 60],
        'quick' => [1200, 60],
        'voice' => [60, 600],
        'image' => [60, 600],
    ];

    private const CACHE_PREFIX = 'quissly_media_rl_';
    private const STORE_PREFIX = 'quissly_store_rl_';
    private const CACHE_TAG = 'QUISSLY_MEDIA_RL';

    /**
     * @param CacheInterface $cache
     * @param RemoteAddress $remoteAddress
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    /**
     * Whether this caller may make another request of this kind now.
     *
     * Counts the attempt, so a rejected caller keeps being rejected for the
     * rest of the window rather than being let through by backing off briefly.
     *
     * @param string $kind MediaSearchClient::KIND_*
     * @param int $limit requests per window (the shopping-activity endpoint allows more)
     * @return bool
     */
    public function allow(string $kind, int $limit = self::LIMIT_PER_WINDOW): bool
    {
        $key = $this->key($kind);
        $count = (int)$this->cache->load($key) + 1;

        $this->cache->save(
            (string)$count,
            $key,
            [self::CACHE_TAG],
            self::WINDOW_SECONDS
        );

        return $count <= $limit;
    }

    /**
     * Whether the whole store may make another request of this kind now.
     *
     * A store-wide limit on top of the per-IP one (the CS-Cart add-on's numbers, after a
     * client's review, 2026-11-03): a bot network rotating addresses gets a fresh per-IP
     * budget for each address, but not past this. Unlike allow(), the window is FIXED (its
     * number is in the key): a cache save restarts its lifetime, so a store-wide counter
     * saved on every request would never expire under steady traffic and would then refuse
     * every shopper. A kind without a limit is not counted.
     *
     * @param string $kind QuickClient::KIND, MediaSearchClient::KIND_*, or 'events'
     * @param int|null $now unix time (tests)
     * @return bool
     */
    public function allowStore(string $kind, ?int $now = null): bool
    {
        if (!isset(self::STORE_LIMITS[$kind])) {
            return true;
        }
        [$limit, $window] = self::STORE_LIMITS[$kind];
        $now = $now ?? time();
        $key = self::STORE_PREFIX . $kind . '_' . intdiv($now, $window);
        $count = (int)$this->cache->load($key) + 1;
        $this->cache->save((string)$count, $key, [self::CACHE_TAG], $window * 2);

        return $count <= $limit;
    }

    /**
     * Cache key for the caller/kind pair; the address is never stored raw.
     *
     * An unresolvable address collapses to a single shared bucket, which is
     * deliberately conservative: unknown callers share one budget rather than
     * each getting a fresh one.
     *
     * @param string $kind
     * @return string
     */
    private function key(string $kind): string
    {
        $address = (string)$this->remoteAddress->getRemoteAddress();
        $identity = $address !== '' ? hash('sha256', $address) : 'unknown';
        return self::CACHE_PREFIX . $kind . '_' . $identity;
    }
}
