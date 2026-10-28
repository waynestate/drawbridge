<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Support;

use Waynestate\Drawbridge\Config\Config;
use Waynestate\Drawbridge\Config\ConfigLoader;

final class Fixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function configArray(): array
    {
        return [
            'gateway' => [
                'issuer' => 'https://auth.apps.example.test',
                'sp_entity_id' => 'https://auth.apps.example.test/_drawbridge/sso/metadata',
            ],
            'apps' => [
                [
                    'id' => 'echo',
                    'host' => 'echo.apps.example.test',
                    'upstream' => 'http://echo:8080',
                    'public' => ['/up', '/assets/', '/admin/open'],
                    'protected' => ['/admin/'],
                    'api' => ['/api/'],
                ],
                [
                    'id' => 'blog',
                    'host' => 'blog.apps.example.test',
                    'upstream' => 'http://blog:8080',
                    'default' => 'public',
                    'mode' => 'both',
                    'protected' => ['/wp-admin/'],
                ],
            ],
        ];
    }

    public static function config(): Config
    {
        return ConfigLoader::fromArray(self::configArray());
    }
}
