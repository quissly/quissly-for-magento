<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Cart;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Quissly\Search\Model\Api\ServiceDirectory;
use Quissly\Search\Model\Config\Settings;

/**
 * Quissly id => Magento product id for one website's catalog, for the chat cart
 * bridge. Built by computing ChatIds::uuid5 over every product assigned to the
 * website, cached for an hour. The namespace is stored at Connect; when it is
 * missing (a store connected before this existed) it is looked up once and
 * saved - at most once per hour if the lookup fails.
 */
class ChatIdMap
{
    private const CACHE_KEY = 'quissly_chat_id_map_';
    private const FAILED_KEY = 'quissly_chat_ns_failed_';
    private const TTL = 3600;

    /**
     * @param Settings $settings
     * @param ServiceDirectory $serviceDirectory
     * @param CollectionFactory $productCollectionFactory
     * @param CacheInterface $cache
     * @param WriterInterface $configWriter
     * @param ReinitableConfigInterface $reinitableConfig
     * @param ChatIds $chatIds
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ServiceDirectory $serviceDirectory,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly CacheInterface $cache,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly ChatIds $chatIds
    ) {
    }

    /**
     * Translate Quissly ids to this website's product ids.
     *
     * @param string[] $quisslyIds already validated (ChatIds::validIds)
     * @param int $websiteId
     * @return array<string, int>
     */
    public function resolve(array $quisslyIds, int $websiteId): array
    {
        return $quisslyIds === [] ? [] : $this->chatIds->resolve($quisslyIds, $this->map($websiteId));
    }

    /**
     * Quissly id => product id for the whole website, cached.
     *
     * @param int $websiteId
     * @return array<string, int>
     */
    private function map(int $websiteId): array
    {
        $namespace = $this->namespaceFor($websiteId);
        if ($namespace === null) {
            return [];
        }
        $key = self::CACHE_KEY . $websiteId . '_' . hash('sha256', $namespace);
        $cached = $this->cache->load($key);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return array_map('intval', $decoded);
            }
        }
        $ids = $this->productCollectionFactory->create()->addWebsiteFilter([$websiteId])->getAllIds();
        $map = $this->chatIds->buildMap($namespace, array_map('intval', $ids));
        $this->cache->save((string)json_encode($map), $key, [], self::TTL);

        return $map;
    }

    /**
     * The stored namespace, looked up and saved when missing.
     *
     * @param int $websiteId
     * @return string|null
     */
    private function namespaceFor(int $websiteId): ?string
    {
        $namespace = $this->settings->searchNamespace($websiteId);
        if ($namespace !== null) {
            return $namespace;
        }
        if ($this->cache->load(self::FAILED_KEY . $websiteId)) {
            return null;
        }
        $found = strtolower((string)$this->serviceDirectory->qsearchNamespace($websiteId));
        if (!preg_match(ChatIds::UUID_PATTERN, $found)) {
            $this->cache->save('1', self::FAILED_KEY . $websiteId, [], self::TTL);
            return null;
        }
        $this->configWriter->save(
            'quissly/connection/search_namespace',
            $found,
            ScopeInterface::SCOPE_WEBSITES,
            $websiteId
        );
        $this->reinitableConfig->reinit();

        return $found;
    }
}
