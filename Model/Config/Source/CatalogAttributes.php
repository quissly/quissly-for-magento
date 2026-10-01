<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The product attributes a merchant may send to Quissly as metadata.
 *
 * Offered: the store's own (user-defined) attributes of a shopper-readable
 * input type, plus the handful of system attributes shoppers actually see
 * (colour, manufacturer, country of manufacture, weight). Never offered:
 * pricing, cost, media, SEO, layout and other internal attributes - a search
 * index and a chat agent that quotes metadata back to shoppers must not be
 * handed a merchant's cost price or supplier codes because a checkbox existed.
 *
 * The filtering itself is a pure static so the unit tier locks it without a
 * Magento attribute collection.
 */
class CatalogAttributes implements OptionSourceInterface
{
    /** System attributes (is_user_defined = 0) that are still shopper-facing. */
    public const ALLOWED_SYSTEM = ['color', 'manufacturer', 'country_of_manufacture', 'weight'];

    /** Input types whose values read as text a shopper would recognise. */
    public const INPUT_TYPES = ['text', 'textarea', 'select', 'multiselect', 'boolean', 'weight'];

    /** Never offered, whatever their flags say. */
    public const EXCLUDED = [
        'sku', 'name', 'description', 'short_description', 'url_key', 'url_path',
        'price', 'special_price', 'special_from_date', 'special_to_date', 'cost', 'msrp',
        'msrp_display_actual_price_type', 'tier_price', 'minimal_price', 'tax_class_id', 'price_view',
        'price_type', 'sku_type', 'weight_type', 'shipment_type',
        'image', 'small_image', 'thumbnail', 'swatch_image', 'media_gallery', 'gallery',
        'image_label', 'small_image_label', 'thumbnail_label',
        'status', 'visibility', 'news_from_date', 'news_to_date', 'category_ids', 'quantity_and_stock_status',
        'required_options', 'has_options', 'options_container', 'gift_message_available',
        'gift_wrapping_available', 'gift_wrapping_price', 'custom_design', 'custom_design_from',
        'custom_design_to', 'custom_layout', 'custom_layout_update', 'custom_layout_update_file', 'page_layout',
        'meta_title', 'meta_keyword', 'meta_description', 'links_purchased_separately', 'links_title',
        'samples_title', 'links_exist', 'created_at', 'updated_at',
        'related_tgtr_position_behavior', 'related_tgtr_position_limit',
        'upsell_tgtr_position_behavior', 'upsell_tgtr_position_limit',
    ];

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $rows = [];
        foreach ($this->collectionFactory->create() as $attribute) {
            $rows[] = [
                'code' => (string)$attribute->getAttributeCode(),
                'label' => trim((string)$attribute->getDefaultFrontendLabel()),
                'input' => (string)$attribute->getFrontendInput(),
                'user_defined' => (bool)$attribute->getIsUserDefined(),
            ];
        }

        $options = [];
        foreach ($this->offered($rows) as $row) {
            $label = $row['label'] !== '' ? $row['label'] : $row['code'];
            $options[] = ['value' => $row['code'], 'label' => sprintf('%s (%s)', $label, $row['code'])];
        }
        return $options;
    }

    /**
     * Which attribute rows are offered, sorted by label.
     *
     * @param array $rows
     * @return array
     */
    public function offered(array $rows): array
    {
        $kept = array_filter($rows, static function (array $row): bool {
            return !in_array($row['code'], self::EXCLUDED, true)
                && in_array($row['input'], self::INPUT_TYPES, true)
                && ($row['user_defined'] || in_array($row['code'], self::ALLOWED_SYSTEM, true));
        });
        usort($kept, static function (array $a, array $b): int {
            $left = $a['label'] !== '' ? $a['label'] : $a['code'];
            $right = $b['label'] !== '' ? $b['label'] : $b['code'];
            return strcasecmp($left, $right);
        });
        return array_values($kept);
    }
}
