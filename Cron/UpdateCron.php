<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Cron;

use Quissly\Search\Model\Update\Updater;

/**
 * The automatic update (Model/Update/Updater): checks GitHub once a day and installs a
 * newer release. Runs hourly; the Updater keeps the day.
 */
class UpdateCron
{
    /**
     * @param Updater $updater
     */
    public function __construct(private readonly Updater $updater)
    {
    }

    /**
     * Check, and install when due.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->updater->run(time());
    }
}
