<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Render;

use Waynestate\Drawbridge\Config\Config;

/**
 * Checks the two secrets Apache reads from the environment before Apache is allowed to start.
 *
 * The rendered config says `OIDCClientSecret "${NAME}"`, never the value. Apache
 * fills the value in when it parses the config, and that is the catch: an
 * undefined variable is left in as the literal text "${NAME}", with only a
 * warning. A gateway whose crypto passphrase is a string printed in this
 * repository is a gateway with no lock on the door, so a missing, short or
 * unquotable value stops the container here instead.
 *
 * Messages name the variable and the problem, never the value.
 */
final class RuntimeSecrets
{
    private const MIN_PASSPHRASE = 32;

    /**
     * @param  array<string, string|false>  $env  Variable name to value (false or missing: not set).
     * @return list<string> Every problem found; empty when both secrets are usable.
     */
    public static function problems(Config $config, array $env): array
    {
        $problems = [];

        foreach ([$config->oidc->clientSecretEnv => 1, RenderOptions::CRYPTO_PASSPHRASE_ENV => self::MIN_PASSPHRASE] as $name => $min) {
            $value = $env[$name] ?? false;

            if ($value === false || $value === '') {
                $problems[] = "The environment variable {$name} is not set.";

                continue;
            }

            if (strlen($value) < $min) {
                $problems[] = "The environment variable {$name} is shorter than {$min} characters.";
            }

            // Apache substitutes the value into a quoted directive argument, so quotes, backslashes, spaces and control
            // characters would change what the directive says.
            if (preg_match('/^[\x21\x23-\x5b\x5d-\x7e]+$/', $value) !== 1) {
                $problems[] = "The environment variable {$name} has a space, quote, backslash or non-ASCII character, which Apache cannot take safely.";
            }
        }

        return $problems;
    }

    /**
     * The current values from the process environment, for the variables the check needs.
     *
     * @return array<string, string|false>
     */
    public static function fromProcess(Config $config): array
    {
        $env = [];

        foreach ([$config->oidc->clientSecretEnv, RenderOptions::CRYPTO_PASSPHRASE_ENV] as $name) {
            $env[$name] = getenv($name);
        }

        return $env;
    }
}
