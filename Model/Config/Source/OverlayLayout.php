<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The search overlay's layout - the Shopify app's "Search overlay layout".
 *
 * Presentation only. Wide spans the screen from 750px of it, center is a
 * compact 760px panel; phones look the same either way.
 */
class OverlayLayout implements OptionSourceInterface
{
    public const WIDE = 'wide';
    public const CENTER = 'center';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::WIDE, 'label' => __('Wide - spans the screen')],
            ['value' => self::CENTER, 'label' => __('Center - a compact panel')],
        ];
    }
}
