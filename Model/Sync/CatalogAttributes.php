<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Sync;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Quissly\Search\Model\Config\Settings;

/**
 * Reads the merchant-chosen product attributes off a product, ready for the
 * wire: one entry per attribute that has a value, as {name: label, value}.
 *
 * Values are what a shopper would read - option labels rather than option
 * ids, "yes" for a ticked boolean (an unticked one is silence, not "no"),
 * weight with the store's unit, free text with tags stripped and whitespace
 * collapsed. Empty values are simply absent; the backend must never see an
 * attribute that says nothing.
 *
 * The normalisation is a pure static so the unit tier locks it without a
 * Magento product.
 */
class CatalogAttributes
{
    /** Longest single value sent; anything longer is not an attribute, it is a description. */
    public const MAX_VALUE_LENGTH = 500;

    private const WEIGHT_UNIT_PATH = 'general/locale/weight_unit';

    /**
     * @param Settings $settings
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * The configured attribute codes for a website.
     *
     * @param int|null $websiteId
     * @return string[]
     */
    public function codes(?int $websiteId): array
    {
        return $this->settings->metadataAttributes($websiteId);
    }

    /**
     * The product's values for the configured attributes, keyed by code.
     *
     * Children loaded through a configurable's type instance carry only the
     * listing attributes, so anything missing is fetched raw in one query
     * rather than silently treated as "same as the parent".
     *
     * @param Product $product
     * @param int|null $websiteId
     * @return array
     */
    public function values(Product $product, ?int $websiteId): array
    {
        $codes = $this->codes($websiteId);
        if ($codes === []) {
            return [];
        }
        $this->loadMissing($product, $codes);

        $out = [];
        foreach ($codes as $code) {
            $attribute = $product->getResource()->getAttribute($code);
            if (!$attribute || !$attribute->getId()) {
                continue;
            }
            $raw = $product->getData($code);
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }
            $input = (string)$attribute->getFrontendInput();
            if ($input === 'select' || $input === 'multiselect') {
                $text = $product->getAttributeText($code);
                $value = $text === false ? null : $text;
            } elseif ($input === 'boolean') {
                $value = (int)$raw === 1 ? 'yes' : null;
            } elseif ($input === 'weight') {
                $value = $this->formatWeight((string)$raw, $this->weightUnit($websiteId));
            } else {
                $value = (string)$raw;
            }
            $label = (string)($attribute->getStoreLabel() ?: $attribute->getDefaultFrontendLabel() ?: $code);
            $entry = $this->entry($label, $value);
            if ($entry !== null) {
                $out[$code] = $entry;
            }
        }
        return $out;
    }

    /**
     * One wire entry, or null when the value says nothing.
     *
     * @param string $label
     * @param mixed $value
     * @return array|null
     */
    public function entry(string $label, $value): ?array
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $item) {
                $item = $this->clean((string)$item);
                if ($item !== '' && !in_array($item, $clean, true)) {
                    $clean[] = $item;
                }
            }
            if ($clean === []) {
                return null;
            }
            $value = count($clean) === 1 ? $clean[0] : $clean;
        } else {
            if ($value === null) {
                return null;
            }
            $value = $this->clean((string)$value);
            if ($value === '') {
                return null;
            }
        }
        $name = strtolower(trim($label));
        return $name === '' ? null : ['name' => $name, 'value' => $value];
    }

    /**
     * A weight as a shopper reads it: trailing zeros dropped, unit appended.
     *
     * @param string $raw
     * @param string $unit
     * @return string|null
     */
    public function formatWeight(string $raw, string $unit): ?string
    {
        $number = (float)$raw;
        if ($number <= 0.0) {
            return null;
        }
        $text = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
        return $unit !== '' ? $text . ' ' . $unit : $text;
    }

    /**
     * Tags stripped, whitespace collapsed, capped.
     *
     * @param string $value
     * @return string
     */
    private function clean(string $value): string
    {
        // Magento keeps option labels and texts with HTML entities ("Lycra&reg;",
        // "&nbsp;"); decode them, then strip tags, so the wire carries the
        // characters a shopper reads, never the markup.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- decoding, not rendering: no escaper does this
        $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        return mb_substr($text, 0, self::MAX_VALUE_LENGTH);
    }

    /**
     * Fetch, in one query, the configured attributes the loaded product lacks.
     *
     * @param Product $product
     * @param string[] $codes
     * @return void
     */
    private function loadMissing(Product $product, array $codes): void
    {
        $missing = array_values(array_filter($codes, static fn (string $code): bool => !$product->hasData($code)));
        if ($missing === [] || !$product->getId()) {
            return;
        }
        $raw = $product->getResource()->getAttributeRawValue(
            (int)$product->getId(),
            $missing,
            (int)$product->getStoreId()
        );
        if (!is_array($raw)) {
            // A single code comes back as a bare value.
            $raw = count($missing) === 1 && $raw !== false ? [$missing[0] => $raw] : [];
        }
        foreach ($raw as $code => $value) {
            $product->setData((string)$code, $value);
        }
        foreach ($missing as $code) {
            if (!$product->hasData($code)) {
                $product->setData($code, null);
            }
        }
    }

    /**
     * The store's weight unit (lbs / kgs) for the scope.
     *
     * @param int|null $websiteId
     * @return string
     */
    private function weightUnit(?int $websiteId): string
    {
        return (string)$this->scopeConfig->getValue(
            self::WEIGHT_UNIT_PATH,
            $websiteId ? ScopeInterface::SCOPE_WEBSITE : ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $websiteId ?: null
        );
    }
}
