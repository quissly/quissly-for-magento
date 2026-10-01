<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Logger;

use Monolog\Logger as MonologLogger;

/**
 * The module's PSR-3 logger, bound to the Quissly handler.
 *
 * Injected wherever the module logs, so [quissly] lines land in their own file
 * instead of Magento's shared logs.
 */
class Logger extends MonologLogger
{
}
