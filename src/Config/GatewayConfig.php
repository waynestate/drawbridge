<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Config;

/**
 * Settings shared by every app behind the gateway.
 */
final readonly class GatewayConfig
{
    /**
     * @param  array<string, string>  $attributes  Which IdP attribute feeds each of sub, email, name, affiliations.
     * @param  array<string, string>  $attributeHeaders  The header each attribute is sent in when an app uses header mode.
     * @param  array<string, list<string>>  $amrMap  SAML AuthnContextClassRef to amr values.
     * @param  list<string>  $extraStripHeaders  Extra header names (or prefixes ending in "-") to remove from every request.
     */
    public function __construct(
        public string $issuer,
        public string $spEntityId,
        public string $assertionHeader,
        public string $userHeader,
        public array $attributeHeaders,
        public int $ttlSeconds,
        public int $sessionSeconds,
        public int $idleSeconds,
        public string $sessionCookie,
        public array $attributes,
        public array $amrMap,
        public array $extraStripHeaders,
    ) {}
}
