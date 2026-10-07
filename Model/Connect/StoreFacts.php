<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Connect;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Directory\Model\CountryFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Quissly\Search\Model\Config\Settings;

/**
 * What Quissly Setup's description draft is written from: the website's own facts.
 *
 * The Shopify app reads the same facts from Shopify (description-draft.server.ts): the store's
 * meta description, its active products and their kinds (here: categories), where the business
 * is, the price range and the brands. Any failure gives no draft, never a failed Setup page.
 */
class StoreFacts
{
    /** Products sampled for brands; the Shopify app samples 250 too. */
    private const SAMPLE = 250;

    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param ProductCollectionFactory $productCollectionFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param CountryFactory $countryFactory
     * @param EavConfig $eavConfig
     * @param Settings $settings
     * @param DescriptionDraft $draft
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CountryFactory $countryFactory,
        private readonly EavConfig $eavConfig,
        private readonly Settings $settings,
        private readonly DescriptionDraft $draft,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The drafted description, or '' when the store gives nothing worth saying.
     *
     * @param int $websiteId
     * @return string
     */
    public function description(int $websiteId): string
    {
        try {
            return (string)$this->draft->compose($this->facts($websiteId));
        } catch (\Throwable $e) {
            $this->logger->info('[quissly] setup description draft unavailable: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * The draft's input.
     *
     * @param int $websiteId
     * @return array
     */
    private function facts(int $websiteId): array
    {
        $store = $this->storeManager->getWebsite($websiteId)->getDefaultStore();
        $storeId = (int)$store->getId();

        $count = (int)$this->products($websiteId)->getSize();
        $priced = $this->products($websiteId)->addPriceData(0, $websiteId);

        return [
            'shop_name' => (string)$this->settings->storeDisplayName($websiteId),
            'meta_description' => (string)$this->scopeConfig->getValue(
                'design/head/default_description',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
            'product_count' => $count,
            'count_is_lower_bound' => false,
            'product_kinds' => [],
            'collections' => $count > 0 ? $this->categories((int)$store->getRootCategoryId(), $storeId) : [],
            'location' => $this->location($websiteId),
            'prices' => $count > 0
                ? ['min' => (float)$priced->getMinPrice(), 'max' => (float)$priced->getMaxPrice(),
                    'currency' => (string)$store->getBaseCurrencyCode()]
                : null,
            'vendors' => $count > 0 ? $this->brands($websiteId) : [],
        ];
    }

    /**
     * The website's products a shopper can find.
     *
     * @param int $websiteId
     * @return \Magento\Catalog\Model\ResourceModel\Product\Collection
     */
    private function products(int $websiteId)
    {
        return $this->productCollectionFactory->create()
            ->addWebsiteFilter($websiteId)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter(
                'visibility',
                ['in' => [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]]
            );
    }

    /**
     * The store's categories with their product counts (Magento's "collections").
     *
     * @param int $rootId
     * @param int $storeId
     * @return array
     */
    private function categories(int $rootId, int $storeId): array
    {
        $collection = $this->categoryCollectionFactory->create()
            ->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'url_key'])
            ->addIsActiveFilter()
            ->addFieldToFilter('path', ['like' => '1/' . $rootId . '/%'])
            ->setLoadProductCount(true);
        $out = [];
        foreach ($collection as $category) {
            $out[] = [
                'title' => (string)$category->getName(),
                'handle' => (string)$category->getUrlKey(),
                'product_count' => (int)$category->getProductCount(),
            ];
        }
        return $out;
    }

    /**
     * City and country from Stores > Configuration > General > Store Information.
     *
     * @param int $websiteId
     * @return array
     */
    private function location(int $websiteId): array
    {
        $read = fn (string $path) => trim((string)$this->scopeConfig->getValue(
            'general/store_information/' . $path,
            ScopeInterface::SCOPE_WEBSITES,
            $websiteId
        ));
        $code = $read('country_id');
        return [
            'city' => $read('city'),
            'country' => $code === '' ? '' : (string)$this->countryFactory->create()->loadByCode($code)->getName(),
        ];
    }

    /**
     * Brand names from a sample of products, where the store has a brand attribute.
     *
     * @param int $websiteId
     * @return string[]
     */
    private function brands(int $websiteId): array
    {
        $codes = [];
        foreach (['brand', 'manufacturer'] as $code) {
            $attribute = $this->eavConfig->getAttribute('catalog_product', $code);
            if ($attribute && $attribute->getId()) {
                $codes[] = $code;
            }
        }
        if ($codes === []) {
            return [];
        }
        $sample = $this->products($websiteId)->addAttributeToSelect($codes)->setPageSize(self::SAMPLE)->setCurPage(1);
        $brands = [];
        foreach ($sample as $product) {
            foreach ($codes as $code) {
                $text = $product->getAttributeText($code);
                if (is_string($text) && trim($text) !== '') {
                    $brands[] = $text;
                    break;
                }
            }
        }
        return $brands;
    }
}
