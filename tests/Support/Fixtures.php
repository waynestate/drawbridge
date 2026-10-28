<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Support;

use Waynestate\Drawbridge\Config\Config;
use Waynestate\Drawbridge\Config\ConfigLoader;

final class Fixtures
{
    /** A made-up Entra-shaped issuer (the zeros are not a tenant). */
    public const OIDC_ISSUER = 'https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/v2.0';

    /**
     * @return array<string, mixed>
     */
    public static function configArray(): array
    {
        return [
            'gateway' => [
                'issuer' => 'https://auth.apps.example.test',
            ],
            'oidc' => [
                'issuer' => self::OIDC_ISSUER,
                'client_id' => '11111111-2222-3333-4444-555555555555',
                'client_secret_env' => 'DRAWBRIDGE_OIDC_CLIENT_SECRET',
                'attribute_claims' => ['affiliations' => 'affiliations'],
                'extra_claims' => ['wsuRoles'],
            ],
            'apps' => [
                [
                    'id' => 'echo',
                    'host' => 'echo.apps.example.test',
                    'upstream' => 'http://echo:8080',
                    'public' => ['/up', '/assets/', '/admin/open'],
                    'protected' => ['/admin/', ['path' => '/admin/vault/', 'auth_context' => 'c1']],
                    'api' => ['/api/', ['path' => '/api/vault/', 'auth_context' => 'c2']],
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
