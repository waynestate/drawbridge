<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Assertion;

use RuntimeException;

/**
 * The Apache side sent the signer something it will not sign.
 */
final class InvalidSigningRequest extends RuntimeException {}
