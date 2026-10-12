<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which suggestions the overlay shows as buttons under its empty bar (the Quissly Shopify
 * app's "Search suggestions" theme setting): the ones Quissly generated from the catalog,
 * or the merchant's own.
 */
class SuggestionsMode implements OptionSourceInterface
{
    public const AUTOMATIC = 'automatic';
    public const MANUAL = 'manual';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::AUTOMATIC, 'label' => __('Automatic')],
            ['value' => self::MANUAL, 'label' => __('Manual')],
        ];
    }
}
