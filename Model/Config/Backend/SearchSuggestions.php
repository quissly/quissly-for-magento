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
use Quissly\Search\Model\Search\SuggestionLanguage;

/**
 * Saves "Search bar suggestions" to Quissly (Model/Search/SearchSuggestions).
 *
 * The list field carries this model and reads its sibling switch (typing_enabled) from the same
 * group. Its value is every language's list as JSON, {"en": [...], "fr": [...]}, from the pill
 * editor (SearchSuggestionsField); the main language's is the main list, the others are written
 * per language (an empty one is removed, so its shoppers get the main list). Only what differs
 * from Quissly is written. A list over the limits is refused before anything is written. The
 * page cache is cleaned after a write: the overlay's list is part of every cached page.
 *
 * A write that changes a list makes it the merchant's own: generation (ShowcaseRunner) never
 * writes over that language's list after that.
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
     * @param SuggestionLanguage $languages
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
        private readonly SuggestionLanguage $languages,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Clean every language's list and refuse one over the limits.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $lists = $this->lists();
        foreach ($lists as $queries) {
            $error = $this->suggestions->validate($queries);
            if ($error !== null) {
                throw new LocalizedException(__($error));
            }
        }
        $this->setValue((string)json_encode($lists, JSON_UNESCAPED_UNICODE));
        return parent::beforeSave();
    }

    /**
     * Write to Quissly each list that differs from what it holds.
     *
     * @return $this
     */
    public function afterSave()
    {
        $result = parent::afterSave();
        $lists = $this->lists();
        $switch = $this->getFieldsetDataValue('typing_enabled');
        $written = false;
        foreach ($this->websitesInScope() as $websiteId) {
            if ($lists === [] || $this->suggestions->serviceId($websiteId) === null) {
                continue; // nothing came from the editor, or not set up in Quissly yet
            }
            $current = $this->suggestions->read($websiteId);
            if ($current === null) {
                $this->messages->addErrorMessage(__('Couldn\'t save the search bar suggestions. Please try again.'));
                continue;
            }
            $main = $current['language'] !== '' ? $current['language'] : $this->languages->websiteLanguage($websiteId);
            $main = $main !== '' ? $main : 'en';
            $enabled = $switch === null ? $current['enabled'] : (string)$switch === '1';

            $queries = $lists[$main] ?? $current['queries'];
            if ($current['enabled'] !== $enabled || $current['queries'] !== $queries) {
                if (!$this->report($this->suggestions->save($websiteId, $enabled, $queries))) {
                    continue;
                }
                $written = true;
                if ($current['queries'] !== $queries) {
                    $this->showcase->merchantSaved($websiteId);
                }
            }
            foreach ($lists as $language => $list) {
                if ($language === $main || ($current['by_language'][$language] ?? []) === $list) {
                    continue;
                }
                if ($this->report($this->suggestions->saveLanguage($websiteId, $language, $list))) {
                    $written = true;
                    $this->showcase->merchantSaved($websiteId, $language);
                }
            }
        }
        if ($written) {
            $this->cacheTypeList->cleanType(PageCache::TYPE_IDENTIFIER);
            $this->cacheTypeList->cleanType(Block::TYPE_IDENTIFIER);
        }
        return $result;
    }

    /**
     * Every language's list from the editor, cleaned: {"en": [...], "fr": [...]}; [] when none came.
     *
     * @return array<string, string[]>
     */
    private function lists(): array
    {
        $decoded = json_decode((string)$this->getValue(), true);
        if (!is_array($decoded)) {
            return [];
        }
        $lists = [];
        foreach ($decoded as $language => $queries) {
            $language = strtolower(trim((string)$language));
            if (preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/', $language)) {
                $lists[$language] = $this->suggestions->clean($queries);
            }
        }
        return $lists;
    }

    /**
     * Show a failed write's reason; true when it was written.
     *
     * @param string|null $error
     * @return bool
     */
    private function report(?string $error): bool
    {
        if ($error !== null) {
            $this->messages->addErrorMessage($error);
            return false;
        }
        return true;
    }

    /**
     * The website whose lists the form showed.
     *
     * The one in scope, or at default scope the default store's website (SearchSuggestionsField
     * shows that one) - never every website, which could write a list over one a merchant never saw.
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
