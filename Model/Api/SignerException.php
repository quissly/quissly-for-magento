<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

/**
 * Thrown when signing fails. NEVER swallow this and send an empty signature -
 * an empty signature produces an undiagnosable bare 401 upstream
 * (the WooCommerce plugin's mystery-401 is the counterexample).
 */
class SignerException extends \RuntimeException
{
}
