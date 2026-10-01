<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger as MonologLogger;

/**
 * Writes the module's operational log to var/log/quissly.log.
 *
 * A dedicated file is a PROMISE, not a preference. README tells merchants this
 * log is safe to send to support, and that guarantee only holds for a file
 * containing our lines and nothing else - Magento's shared system.log carries
 * every other module's output, including data we have no right to forward.
 *
 * Content discipline is unchanged: status codes, counts
 * and stable ids only - never query text, never payloads, never secrets.
 *
 * var/log sits outside the webroot, so the file is not web-readable.
 */
class Handler extends Base
{
    /**
     * Log file, relative to the Magento base directory.
     *
     * @var string
     */
    protected $fileName = '/var/log/quissly.log';

    /**
     * Record everything from INFO upward; DEBUG stays out of a support file.
     *
     * @var int
     */
    protected $loggerType = MonologLogger::INFO;
}
