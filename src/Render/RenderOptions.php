<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Render;

use InvalidArgumentException;

/**
 * Platform facts for one deploy: ports, file paths and switches. These come from environment variables,
 * never from the app config file, so the same config file works on Citadel, OpenShift, Coolify and raw Docker (ADR-0010).
 */
final readonly class RenderOptions
{
    /**
     * @param  list<string>  $trustedProxies  CIDR ranges of the proxies in front (HA-Proxy on Citadel). Empty: trust none.
     */
    public function __construct(
        public string $configFile,
        public string $outputDir,
        public int $port = 8080,
        public string $scheme = 'https',
        public ?int $publicPort = null,
        public bool $secureCookies = true,
        public array $trustedProxies = [],
        public string $idpMetadataFile = '/run/drawbridge/idp-metadata.xml',
        public string $spKeyFile = '/run/drawbridge/sp.key',
        public string $spCertFile = '/run/drawbridge/sp.crt',
        public string $signingKeyFile = '/run/drawbridge/signing.key',
        public string $signingKeyId = 'drawbridge-1',
        public string $phpBinary = '/usr/bin/php',
        public string $consoleBinary = '/opt/drawbridge/bin/drawbridge',
        public int $sessionCacheEntries = 1000,
    ) {
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('The public scheme must be "http" or "https".');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The listen port must be from 1 to 65535.');
        }

        foreach ($trustedProxies as $range) {
            if (preg_match('#^[0-9a-fA-F:.]+(/\d{1,3})?$#', $range) !== 1) {
                throw new InvalidArgumentException("\"{$range}\" is not an IP address or CIDR range.");
            }
        }

        foreach ([$configFile, $outputDir, $idpMetadataFile, $spKeyFile, $spCertFile, $signingKeyFile, $signingKeyId, $phpBinary, $consoleBinary] as $value) {
            if (preg_match('#^[A-Za-z0-9_./:@+=-]+$#', $value) !== 1) {
                throw new InvalidArgumentException("\"{$value}\" has characters that are not allowed in a path or key ID.");
            }
        }
    }

    /**
     * Read the DRAWBRIDGE_* environment variables. Unset means the safe default.
     *
     * @param  array<string, string|false>  $env
     */
    public static function fromEnvironment(array $env, string $outputDir): self
    {
        $get = static fn(string $name, string $default): string => (isset($env[$name]) && $env[$name] !== false && $env[$name] !== '') ? (string) $env[$name] : $default;

        $proxies = array_values(array_filter(array_map('trim', explode(',', $get('DRAWBRIDGE_TRUSTED_PROXIES', '')))));
        $publicPort = $get('DRAWBRIDGE_PUBLIC_PORT', '');

        return new self(
            configFile: $get('DRAWBRIDGE_CONFIG', '/etc/drawbridge/drawbridge.yaml'),
            outputDir: $outputDir,
            port: (int) $get('DRAWBRIDGE_PORT', '8080'),
            scheme: $get('DRAWBRIDGE_PUBLIC_SCHEME', 'https'),
            publicPort: $publicPort === '' ? null : (int) $publicPort,
            secureCookies: strtolower($get('DRAWBRIDGE_COOKIE_SECURE', 'true')) !== 'false',
            trustedProxies: $proxies,
            idpMetadataFile: $get('DRAWBRIDGE_IDP_METADATA_FILE', '/run/drawbridge/idp-metadata.xml'),
            spKeyFile: $get('DRAWBRIDGE_SP_KEY_FILE', '/run/drawbridge/sp.key'),
            spCertFile: $get('DRAWBRIDGE_SP_CERT_FILE', '/run/drawbridge/sp.crt'),
            signingKeyFile: $get('DRAWBRIDGE_SIGNING_KEY_FILE', '/run/drawbridge/signing.key'),
            signingKeyId: $get('DRAWBRIDGE_SIGNING_KEY_ID', 'drawbridge-1'),
            sessionCacheEntries: (int) $get('DRAWBRIDGE_SESSION_CACHE_ENTRIES', '1000'),
        );
    }
}
