<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Block\Adminhtml\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Serialize\Serializer\Json;
use Quissly\Search\Model\Search\SearchSuggestions;
use Quissly\Search\Model\Search\ShowcaseRunner;
use Quissly\Search\Model\Search\SuggestionLanguage;

/**
 * The "Search bar suggestions" fields (typing_enabled, suggestions).
 *
 * Their value lives in Quissly, not in core_config_data (Model/Search/SearchSuggestions), so each
 * renders what Quissly holds for the website in scope - and no "Use Default" box, since there is
 * nothing in Magento to inherit. When Quissly cannot be read (not connected, no search service
 * yet, console unreachable) the list field says so and the switch is not drawn, so a save cannot
 * write an empty list over the real one.
 *
 * The list is the Shopify app's Settings editor (web/js/config-suggestions.js): a Language select
 * over the website's store-view languages (the main one first, "(default)"), that language's
 * suggestions as removable pills, "Add a suggestion", "N of 20" and "Reset to generated" (the list
 * generated from the catalog, Model/Search/ShowcaseRunner). Every language's list goes back in one
 * hidden field as JSON; Model/Config/Backend/SearchSuggestions writes the ones that changed.
 */
class SearchSuggestionsField extends Field
{
    /** @var array|null|false what Quissly holds; false = not read yet */
    private $current = false;

    /**
     * @param Context $context
     * @param SearchSuggestions $suggestions
     * @param ShowcaseRunner $showcase
     * @param SuggestionLanguage $languages
     * @param Json $json
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly SearchSuggestions $suggestions,
        private readonly ShowcaseRunner $showcase,
        private readonly SuggestionLanguage $languages,
        private readonly Json $json,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritdoc
     */
    public function render(AbstractElement $element)
    {
        $element->setCanUseWebsiteValue(false);
        $element->setCanUseDefaultValue(false);
        $element->setCanRestoreToDefault(false);
        if ($this->current() === null && $this->fieldId($element) !== 'suggestions') {
            return '';
        }
        return parent::render($element);
    }

    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $current = $this->current();
        if ($current === null) {
            return '<p class="note"><span>' . $this->escapeHtml(__(
                'Available once the store is connected and its first catalog sync has created search in Quissly '
                . '- or Quissly could not be reached just now; reload to try again.'
            )) . '</span></p>';
        }
        if ($this->fieldId($element) === 'typing_enabled') {
            $element->setValue($current['enabled'] ? '1' : '0');
            return parent::_getElementHtml($element);
        }
        return $this->editorHtml($element, $current);
    }

    /**
     * The pill editor: its configuration for config-suggestions.js and its markup.
     *
     * @param AbstractElement $element
     * @param array $current what Quissly holds
     * @return string
     */
    private function editorHtml(AbstractElement $element, array $current): string
    {
        $languages = $this->languageModels($current);
        $config = [
            'main' => $languages[0]['code'],
            'max' => SearchSuggestions::MAX_COUNT,
            'maxLength' => SearchSuggestions::MAX_LENGTH,
            'languages' => $languages,
            'text' => [
                'remove' => (string)__('Remove'),
                'count' => (string)__('%1 of %2. 10 is usually more than enough, but you can add up to %2.'),
                'emptyMain' => (string)__('No suggestions yet. The bar shows its plain placeholder.'),
                'emptyOther' => (string)__('No list for this language yet: its shoppers see the default list.'),
                'tooLong' => (string)__('Keep each search bar suggestion under %1 characters.'),
                'duplicate' => (string)__('That suggestion is already in the list.'),
            ],
        ];

        return '<div class="q-suggestions" data-q-suggestions data-config="'
            . $this->escapeHtmlAttr((string)$this->json->serialize($config)) . '">'
            . $this->languageSelect($element, $languages)
            . '<input type="hidden" id="' . $this->escapeHtmlAttr($element->getHtmlId()) . '" name="'
            . $this->escapeHtmlAttr($element->getName()) . '" value="" data-q-value>'
            . '<div class="q-pills" data-q-pills aria-live="polite"></div>'
            . '<div class="q-suggestions__add">'
            . '<input type="text" class="input-text admin__control-text" maxlength="'
            . SearchSuggestions::MAX_LENGTH . '" autocomplete="off" data-q-new aria-label="'
            . $this->escapeHtmlAttr(__('Add a suggestion')) . '" placeholder="'
            . $this->escapeHtmlAttr(__('Add a suggestion, e.g. waterproof jacket')) . '">'
            . '<button type="button" class="action-default" data-q-add>' . $this->escapeHtml(__('Add')) . '</button>'
            . '</div>'
            . '<p class="q-suggestions__error" data-q-error hidden></p>'
            . '<p class="note"><span class="q-suggestions__count" data-q-count></span></p>'
            . '<p class="note" data-q-note hidden></p>'
            . '<p><button type="button" class="action-default" data-q-reset hidden>'
            . $this->escapeHtml(__('Reset to generated')) . '</button></p>'
            . '</div>';
    }

    /**
     * The Language select, when the website has more than one language.
     *
     * @param AbstractElement $element
     * @param array $languages
     * @return string
     */
    private function languageSelect(AbstractElement $element, array $languages): string
    {
        if (count($languages) < 2) {
            return '';
        }
        $options = '';
        foreach ($languages as $language) {
            $options .= '<option value="' . $this->escapeHtmlAttr($language['code']) . '">'
                . $this->escapeHtml($language['label']) . '</option>';
        }
        $id = $element->getHtmlId() . '_language';
        return '<div class="q-suggestions__language">'
            . '<label for="' . $this->escapeHtmlAttr($id) . '">' . $this->escapeHtml(__('Language')) . '</label>'
            . '<select id="' . $this->escapeHtmlAttr($id) . '" class="select admin__control-select" data-q-language>'
            . $options . '</select>'
            . '<p class="note"><span>' . $this->escapeHtml(__(
                'Shoppers see the list for the language they browse your store in. '
                . 'A language with no list of its own uses the default one.'
            )) . '</span></p></div>';
    }

    /**
     * Every language the editor offers, main first.
     *
     * The website's store-view languages, and any language Quissly already holds a list for.
     *
     * @param array $current what Quissly holds
     * @return array<int, array{code:string, label:string, queries:string[], generated:string[], note:string}>
     */
    private function languageModels(array $current): array
    {
        $websiteId = $this->websiteId();
        $main = $current['language'] !== ''
            ? $current['language']
            : $this->languages->websiteLanguage($websiteId);
        $codes = [$main !== '' ? $main : 'en'];
        foreach (array_keys($websiteId === null ? [] : $this->languages->otherLanguages($websiteId)) as $code) {
            $codes[] = $code;
        }
        foreach (array_keys($current['by_language']) as $code) {
            $codes[] = $code;
        }

        $models = [];
        foreach (array_values(array_unique($codes)) as $i => $code) {
            $language = $i === 0 ? null : $code;
            $finished = $websiteId === null || $this->showcase->finished($websiteId, $language);
            $models[] = [
                'code' => $code,
                'label' => $i === 0
                    ? (string)__('%1 (default)', $this->languages->name($code))
                    : $this->languages->name($code),
                'queries' => $i === 0 ? $current['queries'] : ($current['by_language'][$code] ?? []),
                'generated' => $websiteId === null ? [] : $this->showcase->generated($websiteId, $language),
                'note' => $finished ? '' : (string)__(
                    'Left empty, suggestions are generated from your catalog after the first catalog sync '
                    . '- each one checked to find products.'
                ),
            ];
        }
        return $models;
    }

    /**
     * What Quissly holds for the website in scope (read once per page).
     *
     * @return array|null
     */
    private function current(): ?array
    {
        if ($this->current === false) {
            $this->current = $this->suggestions->read($this->websiteId());
        }
        return $this->current;
    }

    /**
     * The field's id in system.xml (typing_enabled or suggestions).
     *
     * @param AbstractElement $element
     * @return string
     */
    private function fieldId(AbstractElement $element): string
    {
        return (string)($element->getFieldConfig()['id'] ?? '');
    }

    /**
     * The website in scope; at default scope, the default store's website.
     *
     * @return int|null
     */
    private function websiteId(): ?int
    {
        $scoped = (int)$this->getRequest()->getParam('website', 0);
        if ($scoped > 0) {
            return $scoped;
        }
        try {
            return (int)$this->_storeManager->getDefaultStoreView()->getWebsiteId();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
