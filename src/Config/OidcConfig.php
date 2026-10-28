<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Config;

/**
 * The one OpenID Connect provider every app host signs in through (Microsoft Entra ID in production).
 *
 * Nothing secret lives here. The client secret is read from the environment variable this names, when Apache
 * starts, so it is never in the config file, the rendered Apache config, or the output of any command.
 */
final readonly class OidcConfig
{
    /**
     * @param  array<string, string>  $attributeClaims  Which ID token claim feeds each of email, name, affiliations. A missing key means "not sent".
     * @param  list<string>  $extraClaims  ID token claims copied into the assertion as they are, when present.
     * @param  array<string, list<string>>  $amrMap  An "amr" value the provider sends, mapped to the "amr" values the assertion carries.
     */
    public function __construct(
        public string $issuer,
        public string $metadataUrl,
        public string $clientId,
        public string $clientSecretEnv,
        public string $userClaim,
        public array $attributeClaims,
        public array $extraClaims,
        public array $amrMap,
    ) {}
}
