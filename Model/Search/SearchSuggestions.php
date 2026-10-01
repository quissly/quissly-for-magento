<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Api\ConsoleHttp;
use Quissly\Search\Model\Api\HttpClient;
use Quissly\Search\Model\Api\PanelSession;
use Quissly\Search\Model\Api\ServiceDirectory;
use Quissly\Search\Model\Config\Settings;

/**
 * Search bar suggestions: the queries the search overlay types into its empty bar (port of
 * the Shopify app's "Search bar suggestions"; the WooCommerce and CS-Cart plugins
 * have the same).
 *
 * The list lives in Quissly, not in Magento - in the website's QSearch service's
 * widget_config, under the Shopify app's keys (`client_specific_queries`, a list of strings,
 * and `search_typing_enabled`, absent = on), so every platform and Quissly read one list.
 *  - read():          the public widget-config read; no config row yet (404) = on, none;
 *  - forStorefront(): read() cached five minutes (a failed read, one) - what the overlay types;
 *  - save():          sign in as the website's store (PanelSession, the embedded panel's
 *                     sign-in), read, set OUR two keys, PUT the whole blob back (it replaces
 *                     it, so every other key is carried over).
 * The QSearch service id is looked up once through ServiceDirectory and stored at website
 * scope (quissly/connection/search_service_id), like search_namespace.
 */
class SearchSuggestions
{
    public const QUERIES_KEY = 'client_specific_queries';
    public const TYPING_KEY = 'search_typing_enabled';

    /** At most this many suggestions, each at most MAX_LENGTH characters (as Shopify). */
    public const MAX_COUNT = 20;
    public const MAX_LENGTH = 80;

    private const CACHE_PREFIX = 'quissly_search_suggestions_';
    private const CACHE_TTL = 300;
    private const FAILED_TTL = 60;
    private const TIMEOUT = 10;

    /**
     * @param Settings $settings
     * @param ServiceDirectory $serviceDirectory
     * @param PanelSession $panelSession
     * @param ConsoleHttp $http
     * @param CacheInterface $cache
     * @param WriterInterface $configWriter
     * @param ReinitableConfigInterface $reinitableConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ServiceDirectory $serviceDirectory,
        private readonly PanelSession $panelSession,
        private readonly ConsoleHttp $http,
        private readonly CacheInterface $cache,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Trimmed, non-empty, de-duplicated (case-insensitive), in order.
     *
     * @param mixed $raw
     * @return string[]
     */
    public function clean($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $seen = [];
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $query = trim((string)preg_replace('/\s+/u', ' ', $item));
            $key = mb_strtolower($query);
            if ($query === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $query;
        }
        return $out;
    }

    /**
     * Why a list cannot be saved, or null when it can.
     *
     * @param string[] $queries cleaned list
     * @return string|null
     */
    public function validate(array $queries): ?string
    {
        if (count($queries) > self::MAX_COUNT) {
            return (string)__('Up to %1 search bar suggestions.', self::MAX_COUNT);
        }
        foreach ($queries as $query) {
            if (mb_strlen($query) > self::MAX_LENGTH) {
                return (string)__('Keep each search bar suggestion under %1 characters.', self::MAX_LENGTH);
            }
        }
        return null;
    }

    /**
     * The suggestions a widget_config holds (null = no config row: on, none).
     *
     * @param mixed $config
     * @return array{enabled:bool, queries:string[]}
     */
    public function fromConfig($config): array
    {
        $config = is_array($config) ? $config : [];
        return [
            'enabled' => !(array_key_exists(self::TYPING_KEY, $config) && $config[self::TYPING_KEY] === false),
            'queries' => $this->clean($config[self::QUERIES_KEY] ?? []),
        ];
    }

    /**
     * The current list from Quissly, or null when it cannot be read.
     *
     * @param int|null $websiteId
     * @param bool $lookUp may look the service id up (admin only - never on a storefront page)
     * @return array{enabled:bool, queries:string[]}|null
     */
    public function read(?int $websiteId, bool $lookUp = true): ?array
    {
        $serviceId = $lookUp ? $this->serviceId($websiteId) : $this->settings->searchServiceId($websiteId);
        if ($serviceId === null) {
            return null;
        }
        $response = $this->http->request('GET', $this->readUrl($serviceId, $websiteId), [], null, self::TIMEOUT);
        if ($response === null) {
            return null;
        }
        if ($response['status'] === 404) {
            return $this->fromConfig(null);
        }
        if ($response['status'] !== 200) {
            return null;
        }
        return $this->fromConfig(json_decode($response['body'], true));
    }

    /**
     * What the storefront overlay types: the list when typing is on, else none.
     *
     * @param int|null $websiteId
     * @return string[]
     */
    public function forStorefront(?int $websiteId): array
    {
        $key = self::CACHE_PREFIX . (int)$websiteId;
        $cached = $this->cache->load($key);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $this->clean($decoded);
            }
        }
        // The stored service id only: a shopper's page never waits on a console lookup
        // (Configuration looks it up and stores it).
        $read = $this->read($websiteId, false);
        $queries = $read !== null && $read['enabled'] ? $read['queries'] : [];
        $ttl = $read === null ? self::FAILED_TTL : self::CACHE_TTL;
        $this->cache->save((string)json_encode($queries), $key, [], $ttl);
        return $queries;
    }

    /**
     * Write the list to Quissly. Null when saved, else a merchant-facing reason.
     *
     * @param int|null $websiteId
     * @param bool $enabled
     * @param string[] $queries cleaned, validated list
     * @return string|null
     */
    public function save(?int $websiteId, bool $enabled, array $queries): ?string
    {
        $serviceId = $this->serviceId($websiteId);
        if ($serviceId === null) {
            return (string)__(
                'Search isn\'t set up for this store in Quissly yet, '
                . 'so there is nowhere to save search bar suggestions.'
            );
        }
        $session = $this->panelSession->open($websiteId);
        if (!($session['ok'] ?? false)) {
            return (string)__('Couldn\'t sign in to Quissly to save the search bar suggestions. Please try again.');
        }
        $headers = [
            'Authorization' => 'Bearer ' . $session['access_token'],
            'X-Platform' => HttpClient::X_PLATFORM,
        ];
        $current = $this->http->request('GET', $this->readUrl($serviceId, $websiteId), $headers, null, self::TIMEOUT);
        if ($current === null || ($current['status'] !== 200 && $current['status'] !== 404)) {
            return (string)__('Couldn\'t save the search bar suggestions. Please try again.');
        }
        $config = $current['status'] === 200 ? json_decode($current['body'], true) : [];
        $config = is_array($config) ? $config : [];
        $config[self::QUERIES_KEY] = array_values($queries);
        $config[self::TYPING_KEY] = $enabled;

        $written = $this->http->request(
            'PUT',
            $this->writeUrl($serviceId, $websiteId),
            $headers,
            (string)json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            self::TIMEOUT
        );
        if ($written === null || $written['status'] !== 200) {
            $this->logger->info(sprintf('[quissly] search suggestions save http=%d', $written['status'] ?? 0));
            return (string)__('Couldn\'t save the search bar suggestions. Please try again.');
        }
        $this->cache->remove(self::CACHE_PREFIX . (int)$websiteId);
        return null;
    }

    /**
     * The website's QSearch service id: stored, else looked up once and stored.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function serviceId(?int $websiteId): ?string
    {
        $stored = $this->settings->searchServiceId($websiteId);
        if ($stored !== null) {
            return $stored;
        }
        if ($this->settings->projectId($websiteId) === null) {
            return null;
        }
        $failedKey = self::CACHE_PREFIX . 'lookup_failed_' . (int)$websiteId;
        if ($this->cache->load($failedKey)) {
            return null;
        }
        $found = trim((string)$this->serviceDirectory->serviceId('qsearch', $websiteId));
        if ($found === '') {
            $this->cache->save('1', $failedKey, [], self::FAILED_TTL);
            return null;
        }
        $this->configWriter->save(
            Settings::PATH_SEARCH_SERVICE_ID,
            $found,
            $websiteId ? ScopeInterface::SCOPE_WEBSITES : 'default',
            (int)$websiteId
        );
        $this->reinitableConfig->reinit();
        return $found;
    }

    /**
     * The public widget-config read URL.
     *
     * @param string $serviceId
     * @param int|null $websiteId
     * @return string
     */
    private function readUrl(string $serviceId, ?int $websiteId): string
    {
        return rtrim($this->settings->consoleUrl($websiteId), '/')
            . '/api/v1/services/widget-config/' . rawurlencode($serviceId);
    }

    /**
     * The widget-config write URL (PUT, signed in).
     *
     * @param string $serviceId
     * @param int|null $websiteId
     * @return string
     */
    private function writeUrl(string $serviceId, ?int $websiteId): string
    {
        return rtrim($this->settings->consoleUrl($websiteId), '/')
            . '/api/v1/services/' . rawurlencode($serviceId) . '/widget-config';
    }
}
