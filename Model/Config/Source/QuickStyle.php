<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How the Quick dropdown presents its suggestions.
 *
 * Presentation only - both styles show the same products in the same order.
 */
class QuickStyle implements OptionSourceInterface
{
    public const ROWS = 'rows';
    public const CAROUSEL = 'carousel';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::ROWS, 'label' => __('List - thumbnail, name and price per row')],
            ['value' => self::CAROUSEL, 'label' => __('Carousel - large cards you can scroll through')],
        ];
    }
}
