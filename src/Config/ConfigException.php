<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Config;

use RuntimeException;

/**
 * The config is not usable. Carries every problem found, not just the first.
 */
final class ConfigException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(
            "The gateway config is not valid:\n - " . implode("\n - ", $errors),
        );
    }
}
