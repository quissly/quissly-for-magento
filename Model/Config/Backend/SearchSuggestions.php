<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Backend;

use Magento\Framework\App\Cache\Type\Block;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\PageCache\Model\Cache\Type as PageCache;
use Magento\Store\Model\ScopeInterface;
use Quissly\Search\Model\Search\SearchSuggestions as Suggestions;
use Quissly\Search\Model\Search\ShowcaseRunner;

/**
 * Saves "Search bar suggestions" to Quissly (Model/Search/SearchSuggestions): the list field
 * carries this model and reads its sibling switch (typing_enabled) from the same group, so a
 * save makes ONE write - and only when the list or the switch differs from what Quissly holds.
 * A list over the limits is refused before anything is written. The page cache is cleaned
 * after a write: the overlay's list is part of every cached page.
 *
 * "Use the generated suggestions" (use_generated) replaces the typed list with the one generated
 * from the catalog. A write that changes the list makes it the merchant's own: generation
 * (Model/Search/ShowcaseRunner) never writes over it after that.
 */
class SearchSuggestions extends Value
{
    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param Suggestions $suggestions
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param MessageManager $messages
     * @param ShowcaseRunner $showcase
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        private readonly Suggestions $suggestions,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly MessageManager $messages,
        private readonly ShowcaseRunner $showcase,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Normalise to one suggestion per line and refuse a list over the limits.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $queries = $this->suggestions->clean(preg_split('/\r\n|\r|\n/', (string)$this->getValue()));
        if ((string)$this->getFieldsetDataValue('use_generated') === '1') {
            $websiteId = $this->websitesInScope()[0] ?? null;
            $generated = $websiteId === null ? [] : $this->showcase->generated($websiteId);
            if ($generated !== []) {
                $queries = $generated;
            }
        }
        $error = $this->suggestions->validate($queries);
        if ($error !== null) {
            throw new LocalizedException(__($error));
        }
        $this->setValue(implode("\n", $queries));
        return parent::beforeSave();
    }

    /**
     * Write to Quissly for each website in scope whose list differs.
     *
     * @return $this
     */
    public function afterSave()
    {
        $result = parent::afterSave();
        $queries = $this->suggestions->clean(explode("\n", (string)$this->getValue()));
        $enabled = (string)$this->getFieldsetDataValue('typing_enabled') === '1';
        $written = false;
        foreach ($this->websitesInScope() as $websiteId) {
            if ($this->suggestions->serviceId($websiteId) === null) {
                continue; // not set up in Quissly yet
            }
            $current = $this->suggestions->read($websiteId);
            if ($current !== null && $current['enabled'] === $enabled && $current['queries'] === $queries) {
                continue;
            }
            $error = $this->suggestions->save($websiteId, $enabled, $queries);
            if ($error !== null) {
                $this->messages->addErrorMessage($error);
                continue;
            }
            $written = true;
            if ($current === null || $current['queries'] !== $queries) {
                // The merchant's own list from now on: generation never writes over it.
                $this->showcase->merchantSaved($websiteId);
            }
        }
        if ($written) {
            $this->cacheTypeList->cleanType(PageCache::TYPE_IDENTIFIER);
            $this->cacheTypeList->cleanType(Block::TYPE_IDENTIFIER);
        }
        return $result;
    }

    /**
     * The website whose list the form showed: the one in scope, or at default scope the
     * default store's website (SearchSuggestionsField shows that one) - never every website,
     * which could write a list over one a merchant never saw.
     *
     * @return int[]
     */
    private function websitesInScope(): array
    {
        if ((string)$this->getScope() === ScopeInterface::SCOPE_WEBSITES) {
            return [(int)$this->getScopeId()];
        }
        try {
            return [(int)$this->storeManager->getDefaultStoreView()->getWebsiteId()];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
