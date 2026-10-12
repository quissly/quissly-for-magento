<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\ViewModel;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Search\MostLoved;
use Quissly\Search\Model\Search\SearchSuggestions;
use Quissly\Search\Model\Search\SuggestionLanguage;
use Quissly\Search\Model\Sync\SyncCompletion;

/**
 * View model for the immersive search overlay (default ON).
 *
 * Presentation only: it changes how the search box behaves on click, never
 * which products come back. Output is config-derived (identical for every
 * shopper), so the emitting block stays FPC-safe.
 *
 * It renders ONLY where Quissly is actually answering searches: the merchant's
 * search toggle is on AND the website's first-sync gate is open. Drawing a
 * Quissly search surface over Magento's own results would promise something
 * the page does not deliver, and it is what lets the overlay default to ON
 * without restyling a storefront that Quissly is not running.
 *
 * Note what is NOT in that list: the overlay toggle itself. Whether a theme has
 * a search box of its own is a fact only the browser knows, so the block is
 * emitted wherever Quissly is live and the JS decides what to do with it:
 *
 *   theme HAS a search box  -> the overlay takes it over only if the merchant
 *                              asked for it (isImmersive), else it does nothing
 *   theme has NO search box -> the overlay becomes the search, toggle or not,
 *                              because the alternative is a shop that cannot
 *                              be searched at all
 *
 * A merchant switching the overlay off is declining a presentation change, not
 * asking for their only search entry point to disappear.
 */
class OverlayConfig implements ArgumentInterface
{
    /**
     * @param Settings $settings
     * @param UrlInterface $url
     * @param SyncCompletion $completion
     * @param StoreManagerInterface $storeManager
     * @param SearchSuggestions $suggestions
     * @param SuggestionLanguage $languages
     * @param MostLoved $mostLoved
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly UrlInterface $url,
        private readonly SyncCompletion $completion,
        private readonly StoreManagerInterface $storeManager,
        private readonly SearchSuggestions $suggestions,
        private readonly SuggestionLanguage $languages,
        private readonly MostLoved $mostLoved
    ) {
    }

    /**
     * The merchant's Most loved products for this store view, as JSON cards.
     *
     * None while off or short of picks (Model/Search/MostLoved). Part of the cached page, like chipsJson().
     *
     * @return string
     */
    public function mostLovedJson(): string
    {
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return '[]';
        }
        return (string)json_encode(
            $this->mostLoved->forStorefront($this->websiteId(), $storeId),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * The heading over them: "<store name> Most loved".
     *
     * @return string
     */
    public function mostLovedTitle(): string
    {
        try {
            $name = trim((string)$this->storeManager->getStore()->getFrontendName());
        } catch (\Throwable $e) {
            $name = '';
        }
        return $name !== '' ? (string)__('%1 Most loved', $name) : (string)__('Most loved');
    }

    /**
     * The search bar suggestions the overlay types into its empty bar, as JSON (an empty
     * list keeps the plain placeholder): the list for this store view's language, so a
     * shopper who switches language sees that language's. Cached five minutes by
     * SearchSuggestions; part of the cached page (cached per store view), which a
     * Configuration save of a list cleans.
     *
     * @return string
     */
    public function suggestionsJson(): string
    {
        try {
            $language = $this->languages->storeLanguage((int)$this->storeManager->getStore()->getId());
        } catch (\Throwable $e) {
            $language = '';
        }
        return (string)json_encode(
            array_values($this->suggestions->forStorefront($this->websiteId(), $language)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Whether the overlay should render for the current website.
     *
     * @return bool
     */
    public function shouldRender(): bool
    {
        $websiteId = $this->websiteId();

        return $this->settings->isSearchEnabled($websiteId)
            && $this->completion->isComplete((int)$websiteId);
    }

    /**
     * Whether the merchant asked for the immersive panel.
     *
     * Governs only the case where the theme already has a search box: with this
     * off, that box is left exactly as the theme drew it.
     *
     * @return bool
     */
    public function isImmersive(): bool
    {
        return $this->settings->isOverlayEnabled($this->websiteId());
    }

    /**
     * Whether the suggestions show under the bar as buttons (default ON).
     *
     * @return bool
     */
    public function showsSuggestionsPanel(): bool
    {
        return $this->settings->isOverlaySuggestionsEnabled($this->websiteId());
    }

    /**
     * The buttons themselves, as JSON: the merchant's own list (Manual), or what Quissly
     * generated for this store view's language (Automatic, the default - none until Quissly
     * has generated some). At most SearchSuggestions::MAX_CHIPS. Part of the cached page,
     * like suggestionsJson().
     *
     * @return string
     */
    public function chipsJson(): string
    {
        try {
            $language = $this->languages->storeLanguage((int)$this->storeManager->getStore()->getId());
        } catch (\Throwable $e) {
            $language = '';
        }
        // Manual or generated, as Quissly holds the choice (the Shopify app's keys).
        $chips = $this->suggestions->buttonsForStorefront($this->websiteId(), $language);
        return (string)json_encode(array_values($chips), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The heading over them: "<store name> suggestions" (the overlay writes it in capitals).
     *
     * @return string
     */
    public function suggestionsTitle(): string
    {
        try {
            $name = trim((string)$this->storeManager->getStore()->getFrontendName());
        } catch (\Throwable $e) {
            $name = '';
        }
        return $name !== '' ? (string)__('%1 suggestions', $name) : (string)__('Suggestions');
    }

    /**
     * Merchant-chosen mount point for the search button (CSS selector), or ''.
     *
     * Only used when the theme has no search input. The merchant is doing the
     * identifying, so this keeps the rule that we never insert into a header
     * layout we are guessing about.
     *
     * @return string
     */
    public function mountSelector(): string
    {
        return $this->settings->overlayMountSelector($this->websiteId());
    }

    /**
     * The current website, or null when it cannot be resolved.
     *
     * @return int|null
     */
    private function websiteId(): ?int
    {
        try {
            return (int)$this->storeManager->getStore()->getWebsiteId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The storefront search results URL the overlay submits to.
     *
     * @return string
     */
    public function resultsUrl(): string
    {
        return $this->url->getUrl('catalogsearch/result');
    }
}
