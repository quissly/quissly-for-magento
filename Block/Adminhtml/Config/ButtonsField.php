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
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Search\SearchSuggestions;
use Quissly\Search\Model\Search\SuggestionLanguage;

/**
 * The "Search Suggestions" fields (the Shopify app's): overlay_suggestions_mode and
 * overlay_suggestions_manual.
 *
 * Both show what Quissly holds for the website in scope (search_chips_mode, search_chips_manual);
 * a website whose choice is not in Quissly yet shows Magento's copy, which the next save moves to
 * Quissly (Model/Config/Backend/ManualButtons). No "Use Default" box: the value is the website's
 * own, in Quissly.
 *
 *  - overlay_suggestions_mode: the "Suggestions" select (Automatic / Manual), a note under it
 *    saying what the choice means, and - for Automatic - the buttons the shop shows now, read-only,
 *    per language (so the section never looks empty while the shop has them).
 *  - overlay_suggestions_manual: a Language select (when the website has more than one) over one
 *    list per language, a field per suggestion and one empty field after them - "fill one and the
 *    next field appears" (web/js/config-suggestions.js). Every language's list goes back in one
 *    hidden field as JSON.
 */
class ButtonsField extends Field
{
    /** @var array|null|false what Quissly holds; false = not read yet */
    private $current = false;

    /**
     * @param Context $context
     * @param SearchSuggestions $suggestions
     * @param SuggestionLanguage $languages
     * @param Settings $settings
     * @param Json $json
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly SearchSuggestions $suggestions,
        private readonly SuggestionLanguage $languages,
        private readonly Settings $settings,
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
        return parent::render($element);
    }

    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $websiteId = $this->websiteId();
        $current = $this->current();
        $inQuissly = $current !== null && $current['chips_mode'] !== '';
        if ($this->fieldId($element) === 'overlay_suggestions_mode') {
            $mode = $inQuissly
                ? $current['chips_mode']
                : ($this->settings->overlaySuggestionsMode($websiteId) === 'manual' ? 'manual' : 'automatic');
            $element->setValue($mode);
            return parent::_getElementHtml($element) . $this->modeNote($mode)
                . $this->automaticButtons($current, $mode);
        }
        $lists = $inQuissly ? $current['manual_chips'] : $this->suggestions->localManualLists($websiteId);
        return $this->manualEditor($element, $lists);
    }

    /**
     * The note under the select, changing with it (config-suggestions.js).
     *
     * @param string $mode
     * @return string
     */
    private function modeNote(string $mode): string
    {
        $automatic = (string)__('Quissly picks them from your catalog, per language.');
        $manual = (string)__('You type them, per store language.');
        return '<p class="note"><span data-q-mode-note data-automatic="' . $this->escapeHtmlAttr($automatic)
            . '" data-manual="' . $this->escapeHtmlAttr($manual) . '">'
            . $this->escapeHtml($mode === 'manual' ? $manual : $automatic) . '</span></p>';
    }

    /**
     * What Automatic shows on the shop now, read-only: Quissly's generated list per language.
     *
     * @param array|null $current what Quissly holds
     * @param string $mode
     * @return string
     */
    private function automaticButtons(?array $current, string $mode): string
    {
        $html = '<div class="q-auto-buttons" data-q-auto' . ($mode === 'manual' ? ' hidden' : '') . '>';
        if ($current === null) {
            return $html . '<p class="note"><span>' . $this->escapeHtml(__(
                'Shown here once the store is connected to Quissly and its first catalog sync has finished.'
            )) . '</span></p></div>';
        }
        $websiteId = $this->websiteId();
        $main = $current['language'] !== '' ? $current['language'] : $this->languages->websiteLanguage($websiteId);
        $codes = $this->languageCodes($main, $websiteId);
        $rows = '';
        foreach ($codes as $code) {
            $list = $this->suggestions->pickGenerated($current['generated'], $code, $main);
            if ($list === []) {
                continue;
            }
            $rows .= '<div class="q-auto-buttons__row">';
            if (count($codes) > 1) {
                $rows .= '<span class="q-auto-buttons__language">'
                    . $this->escapeHtml($this->languages->name($code)) . '</span>';
            }
            foreach ($list as $text) {
                $rows .= '<span class="q-auto-buttons__button">' . $this->escapeHtml($text) . '</span>';
            }
            $rows .= '</div>';
        }
        if ($rows === '') {
            return $html . '<p class="note"><span>' . $this->escapeHtml(__(
                'None yet - Quissly creates them from your catalog after the first catalog sync.'
            )) . '</span></p></div>';
        }
        return $html . '<p class="q-auto-buttons__title">' . $this->escapeHtml(__('Your shop shows now:')) . '</p>'
            . $rows . '</div>';
    }

    /**
     * The Manual lists' editor: its configuration for config-suggestions.js and its markup.
     *
     * @param AbstractElement $element
     * @param array $lists {language: string[]}
     * @return string
     */
    private function manualEditor(AbstractElement $element, array $lists): string
    {
        $websiteId = $this->websiteId();
        $main = $this->languages->websiteLanguage($websiteId);
        $main = $main !== '' ? $main : 'en';
        $languages = [];
        foreach ($this->languageCodes($main, $websiteId, array_keys($lists)) as $i => $code) {
            $languages[] = [
                'code' => $code,
                'label' => $i === 0
                    ? (string)__('%1 (default)', $this->languages->name($code))
                    : $this->languages->name($code),
                'queries' => $lists[$code] ?? [],
            ];
        }
        $config = [
            'max' => SearchSuggestions::MAX_CHIPS,
            'maxLength' => SearchSuggestions::MAX_LENGTH,
            'languages' => $languages,
            'text' => [
                'field' => (string)__('Suggestion %1'),
                'placeholder' => (string)__('e.g. black shorts size 32'),
            ],
        ];
        $select = '';
        if (count($languages) > 1) {
            $options = '';
            foreach ($languages as $language) {
                $options .= '<option value="' . $this->escapeHtmlAttr($language['code']) . '">'
                    . $this->escapeHtml($language['label']) . '</option>';
            }
            $id = $element->getHtmlId() . '_language';
            $select = '<div class="q-suggestions__language">'
                . '<label for="' . $this->escapeHtmlAttr($id) . '">' . $this->escapeHtml(__('Language')) . '</label>'
                . '<select id="' . $this->escapeHtmlAttr($id)
                . '" class="select admin__control-select" data-q-language>'
                . $options . '</select>'
                . '<p class="note"><span>' . $this->escapeHtml(__(
                    'Shoppers see the list for the language they browse your store in. '
                    . 'A language with no list of its own uses the default one.'
                )) . '</span></p></div>';
        }
        return '<div class="q-suggestions" data-q-fields data-config="'
            . $this->escapeHtmlAttr((string)$this->json->serialize($config)) . '">'
            . $select
            . '<input type="hidden" id="' . $this->escapeHtmlAttr($element->getHtmlId()) . '" name="'
            . $this->escapeHtmlAttr($element->getName()) . '" value="" data-q-value>'
            . '<div class="q-fields" data-q-list></div>'
            . '</div>';
    }

    /**
     * The website's languages, the main one first, plus any $extra.
     *
     * @param string $main
     * @param int|null $websiteId
     * @param string[] $extra
     * @return string[]
     */
    private function languageCodes(string $main, ?int $websiteId, array $extra = []): array
    {
        $codes = [$main !== '' ? $main : 'en'];
        foreach (array_keys($websiteId === null ? [] : $this->languages->otherLanguages($websiteId)) as $code) {
            $codes[] = $code;
        }
        foreach ($extra as $code) {
            $codes[] = (string)$code;
        }
        return array_values(array_unique($codes));
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
     * The field's id in system.xml.
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
