<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Quissly environment options.
 *
 * LIVE-CONFIRMED against the deployed API: the backend enum is
 * demo|dev|test|staging|prod - note "staging", never "stage". An invalid value
 * is rejected with a 422 naming the allowed set.
 */
class Environment implements OptionSourceInterface
{
    public const ENV_DEMO = 'demo';
    public const ENV_DEV = 'dev';
    public const ENV_TEST = 'test';
    public const ENV_STAGING = 'staging';
    public const ENV_PROD = 'prod';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::ENV_PROD, 'label' => __('Production')],
            ['value' => self::ENV_STAGING, 'label' => __('Staging')],
            ['value' => self::ENV_TEST, 'label' => __('Test')],
            ['value' => self::ENV_DEV, 'label' => __('Development')],
            ['value' => self::ENV_DEMO, 'label' => __('Demo')],
        ];
    }
}
