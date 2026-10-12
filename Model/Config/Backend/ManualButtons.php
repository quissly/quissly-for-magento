<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Store\Model\ScopeInterface;
use Quissly\Search\Model\Search\SearchSuggestions;

/**
 * Saves "Search Suggestions" (the buttons under the overlay's empty bar) to Quissly, in the
 * Shopify app's keys: the choice (its sibling field overlay_suggestions_mode) and every language's
 * Manual list (this field: {"en": [...], "fr": [...]} from ButtonsField's editor) - only what
 * differs from Quissly is written. Magento keeps the same JSON as its copy, which the storefront
 * uses while a website's choice is not in Quissly yet. A list over the limits (10, each under 80
 * characters) is refused before anything is written; the page cache is cleaned (StorefrontSetting).
 */
class ManualButtons extends StorefrontSetting
{
    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param SearchSuggestions $suggestions
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param MessageManager $messages
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        private readonly SearchSuggestions $suggestions,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly MessageManager $messages,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Clean every language's list, refuse one over the limits, keep the JSON as Magento's copy.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $lists = $this->lists();
        foreach ($lists as $list) {
            if (count($list) > SearchSuggestions::MAX_CHIPS) {
                throw new LocalizedException(
                    __('Add at most %1 suggestions to show under the search bar.', SearchSuggestions::MAX_CHIPS)
                );
            }
            foreach ($list as $text) {
                if (mb_strlen($text) > SearchSuggestions::MAX_LENGTH) {
                    throw new LocalizedException(
                        __('Keep each search bar suggestion under %1 characters.', SearchSuggestions::MAX_LENGTH)
                    );
                }
            }
        }
        $this->setValue((string)json_encode($lists, JSON_UNESCAPED_UNICODE));
        return parent::beforeSave();
    }

    /**
     * Write the choice and the lists to Quissly when they differ from what it holds.
     *
     * @return $this
     */
    public function afterSave()
    {
        $result = parent::afterSave();
        $lists = $this->lists();
        $mode = (string)$this->getFieldsetDataValue('overlay_suggestions_mode') === 'manual' ? 'manual' : 'automatic';
        foreach ($this->websitesInScope() as $websiteId) {
            if ($this->suggestions->serviceId($websiteId) === null) {
                continue; // not set up in Quissly yet: Magento's copy stands
            }
            $current = $this->suggestions->read($websiteId);
            if ($current === null) {
                $this->messages->addErrorMessage(__('Couldn\'t save the search bar suggestions. Please try again.'));
                continue;
            }
            // phpcs:ignore Magento2.PHP.ReturnValueCheck -- the same lists in any language order.
            if ($current['chips_mode'] === $mode && $current['manual_chips'] == $lists) {
                continue;
            }
            $error = $this->suggestions->saveButtons($websiteId, $mode, $lists);
            if ($error !== null) {
                $this->messages->addErrorMessage($error);
            }
        }
        return $result;
    }

    /**
     * Every language's list from the editor, cleaned, empty ones left out.
     *
     * @return array<string, string[]>
     */
    private function lists(): array
    {
        $decoded = json_decode((string)$this->getValue(), true);
        $lists = [];
        foreach (is_array($decoded) ? $decoded : [] as $language => $queries) {
            $language = strtolower(trim((string)$language));
            $queries = $this->suggestions->clean(is_array($queries) ? $queries : []);
            if (preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/', $language) && $queries !== []) {
                $lists[$language] = $queries;
            }
        }
        return $lists;
    }

    /**
     * The website whose lists the form showed.
     *
     * The one in scope, or at default scope the default store's website (ButtonsField shows that one).
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
