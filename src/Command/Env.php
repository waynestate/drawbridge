<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Reads the DRAWBRIDGE_* environment variables the commands share.
 */
final class Env
{
    public const DEFAULT_CONFIG = '/etc/drawbridge/drawbridge.yaml';

    public static function configPath(InputInterface $input): string
    {
        $option = $input->getOption('config');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $env = getenv('DRAWBRIDGE_CONFIG');

        return is_string($env) && $env !== '' ? $env : self::DEFAULT_CONFIG;
    }

    /**
     * @return array<string, string|false>
     */
    public static function all(): array
    {
        $names = [
            'DRAWBRIDGE_CONFIG', 'DRAWBRIDGE_PORT', 'DRAWBRIDGE_PUBLIC_SCHEME', 'DRAWBRIDGE_PUBLIC_PORT',
            'DRAWBRIDGE_COOKIE_SECURE', 'DRAWBRIDGE_TRUSTED_PROXIES', 'DRAWBRIDGE_SIGNING_KEY_FILE',
            'DRAWBRIDGE_SIGNING_KEY_ID', 'DRAWBRIDGE_SESSION_CACHE_ENTRIES',
        ];

        $env = [];

        foreach ($names as $name) {
            $env[$name] = getenv($name);
        }

        return $env;
    }
}
