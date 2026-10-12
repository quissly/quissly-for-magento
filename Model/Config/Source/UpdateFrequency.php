<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Quissly\Search\Model\Update\Release;

/**
 * "Update check frequency" (Model/Update/Updater): 5 minutes for testing, 30 minutes (the
 * default) to a day, or Never.
 */
class UpdateFrequency implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $labels = [
            300 => __('Every 5 minutes (For testing)'),
            1800 => __('Every 30 minutes'),
            3600 => __('Every hour'),
            21600 => __('Every 6 hours'),
            43200 => __('Every 12 hours'),
            86400 => __('Once a day'),
            Release::NEVER => __('Never'),
        ];
        $options = [];
        foreach (Release::FREQUENCIES as $seconds) {
            $options[] = ['value' => (string)$seconds, 'label' => $labels[$seconds]];
        }
        return $options;
    }
}
