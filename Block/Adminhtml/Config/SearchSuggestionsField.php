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
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Quissly\Search\Model\Search\SearchSuggestions;
use Quissly\Search\Model\Search\ShowcaseRunner;

/**
 * The "Search bar suggestions" fields (typing_enabled, suggestions, use_generated). Their value lives in
 * Quissly, not in core_config_data (Model/Search/SearchSuggestions), so each renders what
 * Quissly holds for the website in scope - and no "Use Default" box, since there is nothing
 * in Magento to inherit. When Quissly cannot be read (not connected, no search service yet,
 * console unreachable) the list field says so and the switch is not drawn, so a save cannot
 * write an empty list over the real one.
 *
 * use_generated offers the list generated from the catalog (Model/Search/ShowcaseRunner) when
 * there is one that differs from the current list; while the list is empty and generation has
 * not finished it only says one is on its way; otherwise it is not drawn.
 */
class SearchSuggestionsField extends Field
{
    /**
     * Live "N of 20" under the list: its distinct, non-empty lines - the save cleans the list
     * the same way. __TEXTAREA__ / __COUNTER__ / __MAX__ are filled in by countHtml().
     */
    private const COUNT_SCRIPT = '(function(){var t=document.getElementById("__TEXTAREA__"),'
        . 'c=document.getElementById("__COUNTER__");if(!t||!c){return;}'
        . 'function n(){var s={},k=0;t.value.split(/\r\n|\r|\n/).forEach(function(l){'
        . 'l=l.trim().toLowerCase();if(l&&!s[l]){s[l]=1;k++;}});c.textContent=k;'
        . 'c.parentNode.style.color=k>__MAX__?"#b32d2e":"";}t.addEventListener("input",n);n();})();';

    /** @var array{enabled:bool, queries:string[]}|null|false false = not read yet */
    private $current = false;

    /**
     * @param Context $context
     * @param SearchSuggestions $suggestions
     * @param ShowcaseRunner $showcase
     * @param SecureHtmlRenderer $secureRenderer
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly SearchSuggestions $suggestions,
        private readonly ShowcaseRunner $showcase,
        private readonly SecureHtmlRenderer $secureRenderer,
        array $data = []
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    /**
     * @inheritdoc
     */
    public function render(AbstractElement $element)
    {
        $element->setCanUseWebsiteValue(false);
        $element->setCanUseDefaultValue(false);
        $element->setCanRestoreToDefault(false);
        $field = $this->fieldId($element);
        if ($this->current() === null && $field !== 'suggestions') {
            return '';
        }
        if ($field === 'use_generated' && $this->generatedNote() === null) {
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
        $field = $this->fieldId($element);
        if ($field === 'use_generated') {
            $note = '<p class="note"><span>' . $this->escapeHtml($this->generatedNote()) . '</span></p>';
            if ($this->offeredList() === []) {
                return $note; // on its way: nothing to choose yet
            }
            $element->setValue('0');
            return parent::_getElementHtml($element) . $note;
        }
        if ($field === 'typing_enabled') {
            $element->setValue($current['enabled'] ? '1' : '0');
            return parent::_getElementHtml($element);
        }
        $element->setValue(implode("\n", $current['queries']));
        return parent::_getElementHtml($element) . $this->countHtml($element, count($current['queries']));
    }

    /**
     * "N of 20" under the list (the Shopify app's), kept current while the merchant types.
     *
     * @param AbstractElement $element
     * @param int $count
     * @return string
     */
    private function countHtml(AbstractElement $element, int $count): string
    {
        $counterId = $element->getHtmlId() . '_count';
        $text = str_replace(
            '{count}',
            '<span id="' . $this->escapeHtmlAttr($counterId) . '">' . $count . '</span>',
            $this->escapeHtml(__(
                '%1 of %2. 10 is usually more than enough, but you can add up to %2.',
                '{count}',
                SearchSuggestions::MAX_COUNT
            ))
        );
        $script = str_replace(
            ['__TEXTAREA__', '__COUNTER__', '__MAX__'],
            [$this->escapeJs($element->getHtmlId()), $this->escapeJs($counterId), (string)SearchSuggestions::MAX_COUNT],
            self::COUNT_SCRIPT
        );
        return '<p class="note"><span>' . $text . '</span></p>'
            . $this->secureRenderer->renderTag('script', [], $script, false);
    }

    /**
     * What Quissly holds for the website in scope (read once per page).
     *
     * @return array{enabled:bool, queries:string[]}|null
     */
    private function current(): ?array
    {
        if ($this->current === false) {
            $this->current = $this->suggestions->read($this->websiteId());
        }
        return $this->current;
    }

    /**
     * The generated list, when it differs from the current one (else []).
     *
     * @return string[]
     */
    private function offeredList(): array
    {
        $websiteId = $this->websiteId();
        $generated = $websiteId === null ? [] : $this->showcase->generated($websiteId);
        return $generated !== ($this->current()['queries'] ?? null) ? $generated : [];
    }

    /**
     * What use_generated says.
     *
     * The generated list to switch to, that one is on its way, or null (nothing to say - the
     * field is not drawn).
     *
     * @return string|null
     */
    private function generatedNote(): ?string
    {
        $offered = $this->offeredList();
        if ($offered !== []) {
            return (string)__('Generated from your catalog: %1', implode(' · ', $offered));
        }
        $websiteId = $this->websiteId();
        if (($this->current()['queries'] ?? null) === [] && $websiteId !== null
            && !$this->showcase->finished($websiteId)
        ) {
            return (string)__(
                'Left empty, suggestions are generated from your catalog after the first catalog sync '
                . '- each one checked to find products.'
            );
        }
        return null;
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
