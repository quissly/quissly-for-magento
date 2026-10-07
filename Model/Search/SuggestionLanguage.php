<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Search;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Which language a search bar suggestion list is in, and which list a shopper sees.
 *
 * The Quissly Shopify app's scheme (showcase-queries.server.ts, `suggestionsForLocale`), so one
 * service's widget_config reads the same on every platform:
 *
 *   client_specific_queries             the main list, in the store's main language
 *   client_specific_queries_language    that language ("en"); absent on lists saved before
 *   client_specific_queries_by_language { "fr": [...], "ka": [...] }, one per other language
 *
 * Language keys are Shopify's: the language code, lowercased, with a region only where Shopify
 * keeps one (pt-br, pt-pt, zh-cn, zh-tw). Magento's language is the store view's
 * general/locale/code ("fr_FR" -> "fr"); a website's main language is its default store view's.
 */
class SuggestionLanguage
{
    public const PRIMARY_KEY = 'client_specific_queries_language';
    public const BY_LANGUAGE_KEY = 'client_specific_queries_by_language';

    /** Locales whose region Shopify keeps in the language code. */
    private const REGIONAL = [
        'pt_br' => 'pt-br', 'pt_pt' => 'pt-pt', 'zh_hans_cn' => 'zh-cn', 'zh_hant_tw' => 'zh-tw',
        'zh_hant_hk' => 'zh-tw', 'zh_cn' => 'zh-cn', 'zh_tw' => 'zh-tw',
    ];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * "fr_FR" -> "fr", "pt_BR" -> "pt-br"; '' for no locale.
     *
     * @param string $locale
     * @return string
     */
    public function key(string $locale): string
    {
        $locale = strtolower(trim(str_replace('-', '_', $locale)));
        if ($locale === '') {
            return '';
        }
        return self::REGIONAL[$locale] ?? explode('_', $locale)[0];
    }

    /**
     * A store view's language key.
     *
     * @param int $storeId
     * @return string
     */
    public function storeLanguage(int $storeId): string
    {
        return $this->key(
            (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId)
        );
    }

    /**
     * The website's main language: its default store view's.
     *
     * @param int|null $websiteId
     * @return string
     */
    public function websiteLanguage(?int $websiteId): string
    {
        try {
            $store = $this->storeManager->getWebsite((int)$websiteId)->getDefaultStore();
            return $store ? $this->storeLanguage((int)$store->getId()) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Every other language the website's active store views use, each with its first store view.
     *
     * @param int $websiteId
     * @return array<string, int> language => store id (the one its catalog facts are read from)
     */
    public function otherLanguages(int $websiteId): array
    {
        $main = $this->websiteLanguage($websiteId);
        $languages = [];
        try {
            foreach ($this->storeManager->getWebsite($websiteId)->getStores() as $store) {
                $language = $this->storeLanguage((int)$store->getId());
                if ($language !== '' && $language !== $main && !isset($languages[$language]) && $store->isActive()) {
                    $languages[$language] = (int)$store->getId();
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $languages;
    }

    /**
     * The list a shopper in $language sees.
     *
     * The main list when $language is the main language; else that language's own list, then its
     * base language's ("pt-br" -> "pt"); else the main list.
     *
     * @param array $lists {queries: string[], language: string, by_language: array<string, string[]>}
     * @param string $language the shopper's language key
     * @param string $primary the main list's language when the config does not say
     * @return string[]
     */
    public function pick(array $lists, string $language, string $primary): array
    {
        $main = $lists['language'] !== '' ? $lists['language'] : $primary;
        if ($language === '' || $language === $main) {
            return $lists['queries'];
        }
        $byLanguage = $lists['by_language'];
        $base = explode('-', $language)[0];
        return $byLanguage[$language] ?? $byLanguage[$base] ?? $lists['queries'];
    }

    /**
     * A language key's name in English ("fr" -> "French"), the key itself when unknown.
     *
     * @param string $key
     * @return string
     */
    public function name(string $key): string
    {
        if (class_exists(\Locale::class)) {
            $name = (string)\Locale::getDisplayLanguage(str_replace('-', '_', $key), 'en');
            if ($name !== '' && strtolower($name) !== strtolower($key)) {
                return $name;
            }
        }
        return $key;
    }
}
