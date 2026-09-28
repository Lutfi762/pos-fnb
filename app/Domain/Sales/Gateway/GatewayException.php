<?php

declare(strict_types=1);

namespace App\Domain\Sales\Gateway;

use RuntimeException;

/**
 * The gateway said no, or did not answer. The message is for the cashier.
 */
class GatewayException extends RuntimeException {}
