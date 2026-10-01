<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Block\Adminhtml\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a multiselect config field as a list of checkboxes.
 *
 * A native multiselect makes every plain click a single selection; adding
 * one attribute means holding Cmd or Ctrl, and a stray click wipes the list
 * (2026-09-10). Checkboxes toggle one attribute at a time and nothing
 * else changes underneath: the same field name is posted as an array, the
 * backend model joins it, and Magento's own "Use system value" tick still
 * disables every input in the row.
 *
 * The hidden empty entry lets "nothing selected" save as an empty list, which
 * a bare checkbox list could not express (an unchecked box posts nothing).
 */
class AttributeChecklist extends Field
{
    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $name = (string)$element->getName();
        if (!str_ends_with($name, '[]')) {
            $name .= '[]';
        }
        $id = (string)$element->getHtmlId();
        $disabled = $element->getDisabled() ? ' disabled="disabled"' : '';
        $selected = $this->selectedValues($element->getValue());

        $html = '<div class="quissly-checklist" id="' . $this->escapeHtmlAttr($id) . '">'
            . '<input type="hidden" name="' . $this->escapeHtmlAttr($name) . '" value=""' . $disabled . '/>';
        foreach ((array)$element->getValues() as $option) {
            $value = (string)($option['value'] ?? '');
            if ($value === '') {
                continue;
            }
            $boxId = $id . '_' . preg_replace('/[^a-z0-9_]/i', '_', $value);
            $checked = in_array($value, $selected, true) ? ' checked="checked"' : '';
            $html .= '<label class="quissly-checklist__item" for="' . $this->escapeHtmlAttr($boxId) . '">'
                . '<input type="checkbox" id="' . $this->escapeHtmlAttr($boxId) . '"'
                . ' name="' . $this->escapeHtmlAttr($name) . '"'
                . ' value="' . $this->escapeHtmlAttr($value) . '"' . $checked . $disabled . '/>'
                . '<span>' . $this->escapeHtml((string)($option['label'] ?? $value)) . '</span>'
                . '</label>';
        }
        $html .= '</div>';

        return $this->styles() . $html;
    }

    /**
     * The stored value, whether it arrives as a list or as the joined string.
     *
     * @param mixed $value
     * @return string[]
     */
    private function selectedValues($value): array
    {
        if (is_array($value)) {
            $list = $value;
        } else {
            $list = explode(',', (string)$value);
        }
        return array_values(array_filter(array_map('trim', array_map('strval', $list)), 'strlen'));
    }

    /**
     * Layout for the list.
     *
     * Two columns on a wide screen, one on a narrow one; the row keeps its
     * normal height instead of the multiselect's scrolling box.
     *
     * @return string
     */
    private function styles(): string
    {
        return '<style>'
            . '.quissly-checklist{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));'
            . 'gap:4px 16px;max-width:640px}'
            . '.quissly-checklist__item{display:flex;align-items:center;gap:8px;margin:0;padding:3px 0;'
            . 'cursor:pointer;font-weight:normal}'
            . '.quissly-checklist__item input{margin:0;flex:0 0 auto}'
            . '.quissly-checklist__item input:disabled+span{color:#999}'
            . '</style>';
    }
}
