<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Sync\CatalogAttributes;

/**
 * Catalog facts for ShowcaseQueries: the 100 most recently changed searchable products of a
 * website - name, deepest category (the product "type"), brand (manufacturer / brand),
 * configurable options and the Catalog data attributes with their values, lowest price -
 * plus the store's name and base currency.
 */
class ShowcaseCatalog
{
    private const LIMIT = 100;

    /**
     * @param CollectionFactory $collectionFactory
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param CatalogAttributes $catalogAttributes
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CatalogAttributes $catalogAttributes
    ) {
    }

    /**
     * The facts for one website.
     *
     * @param int $websiteId
     * @return array
     */
    public function facts(int $websiteId): array
    {
        $store = $this->storeManager->getWebsite($websiteId)->getDefaultStore();
        $storeId = (int)$store->getId();

        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToSelect('name')
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter(
                'visibility',
                ['in' => [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]]
            )
            ->addMinimalPrice()
            ->setOrder('updated_at', 'DESC')
            ->setPageSize(self::LIMIT)
            ->setCurPage(1);

        $products = [];
        foreach ($collection as $product) {
            /** @var Product $product */
            [$options, $vendor] = $this->options($product, $websiteId);
            $price = (float)($product->getMinimalPrice() ?: $product->getFinalPrice() ?: $product->getPrice());
            $products[] = [
                'title' => (string)$product->getName(),
                'product_type' => $this->deepestCategory($product),
                'category' => null,
                'vendor' => $vendor,
                'options' => $options,
                'min_price' => $price > 0 ? $price : null,
            ];
        }

        $name = (string)$this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return [
            'shop_name' => $name !== '' ? $name : (string)$store->getWebsite()->getName(),
            'currency' => (string)$store->getBaseCurrencyCode(),
            'products' => $products,
        ];
    }

    /**
     * The product's options (configurable attributes + Catalog data attributes) and brand.
     *
     * @param Product $product
     * @param int $websiteId
     * @return array{0: array, 1: string}
     */
    private function options(Product $product, int $websiteId): array
    {
        $options = [];
        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            foreach ($product->getTypeInstance()->getConfigurableAttributesAsArray($product) as $attribute) {
                $options[] = [
                    'name' => (string)($attribute['label'] ?? ''),
                    'values' => array_values(array_filter(array_map(
                        fn ($v) => trim((string)($v['label'] ?? '')),
                        (array)($attribute['values'] ?? [])
                    ), 'strlen')),
                ];
            }
        }
        $vendor = '';
        foreach ($this->catalogAttributes->values($product, $websiteId) as $entry) {
            $values = is_array($entry['value'])
                ? $entry['value']
                : preg_split('/\s*[,|]\s*/u', (string)$entry['value']);
            $values = array_values(array_filter(array_map('trim', $values), 'strlen'));
            if (in_array($entry['name'], ['manufacturer', 'brand'], true) && $vendor === '' && $values !== []) {
                $vendor = $values[0];
            }
            $options[] = ['name' => (string)$entry['name'], 'values' => $values];
        }
        return [$options, $vendor];
    }

    /**
     * The deepest category's name ("Jackets", not "Men"), '' when none.
     *
     * @param Product $product
     * @return string
     */
    private function deepestCategory(Product $product): string
    {
        $best = '';
        $level = -1;
        foreach ($product->getCategoryCollection()->addAttributeToSelect('name') as $category) {
            if ((int)$category->getLevel() >= 2 && $category->getName() && (int)$category->getLevel() > $level) {
                $level = (int)$category->getLevel();
                $best = (string)$category->getName();
            }
        }
        return $best;
    }
}
