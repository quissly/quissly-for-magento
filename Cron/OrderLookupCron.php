<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Cron;

use Quissly\Search\Model\Orders\LookupRegistration;

/**
 * Hourly: tell Quissly where each connected website answers order lookups, when due
 * (Model/Orders/LookupRegistration).
 */
class OrderLookupCron
{
    /**
     * @param LookupRegistration $registration
     */
    public function __construct(
        private readonly LookupRegistration $registration
    ) {
    }

    /**
     * Register what is due.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->registration->run(time());
    }
}
