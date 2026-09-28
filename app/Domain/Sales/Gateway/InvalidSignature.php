<?php

declare(strict_types=1);

namespace App\Domain\Sales\Gateway;

use RuntimeException;

/**
 * A webhook that did not prove it came from the gateway.
 */
class InvalidSignature extends RuntimeException {}
