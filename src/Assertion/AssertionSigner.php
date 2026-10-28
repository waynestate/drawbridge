<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Assertion;

use DateInterval;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Token\Builder;
use Psr\Clock\ClockInterface;
use Waynestate\Drawbridge\Config\Config;

/**
 * Signs the short-lived assertion that SESAME's GatewayDriver verifies.
 *
 * Claims: iss (DRAWBRIDGE, not the provider), aud (the one app's audience),
 * sub (the configured user claim), iat, nbf, exp, a unique jti, auth_time,
 * amr and acrs when the ID token says so, the optional email, name and
 * affiliations that SESAME turns into attributes, and any configured extra
 * claims. The header carries a key ID for rotation and its own type, so the
 * token cannot pass as an OAuth 2.0 access token.
 *
 * The audience comes from the gateway config, looked up by Host. The signer
 * never takes an audience from the request line, and refuses an ID token
 * from any issuer but the configured one.
 */
final class AssertionSigner
{
    public const TYPE = 'drawbridge+jwt';

    private const MAX_TEXT = 512;

    private const MAX_LIST = 64;

    private const MAX_EXTRA_JSON = 2048;

    public function __construct(
        private readonly Config $config,
        private readonly Key $privateKey,
        private readonly string $keyId,
        private readonly ClockInterface $clock,
    ) {}

    public function sign(SigningRequest $request): string
    {
        $app = $this->config->appForHost($request->host);

        if ($app === null) {
            throw new InvalidSigningRequest('No app is configured for the requested host.');
        }

        $oidc = $this->config->oidc;
        $claims = $request->claims;

        // mod_auth_openidc has checked the issuer against the provider metadata; this checks it against the config,
        // so a metadata URL that points somewhere unexpected still cannot mint assertions.
        if (($claims['iss'] ?? null) !== $oidc->issuer) {
            throw new InvalidSigningRequest('The ID token is not from the configured issuer.');
        }

        $subject = self::text($claims[$oidc->userClaim] ?? null);

        if ($subject === null) {
            throw new InvalidSigningRequest('The ID token has no usable user claim.');
        }

        $gateway = $this->config->gateway;
        $now = $this->clock->now();

        $builder = (new Builder(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates()))
            ->issuedBy(self::nonEmpty($gateway->issuer))
            ->permittedFor(self::nonEmpty($app->audience))
            ->relatedTo($subject)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->add(new DateInterval('PT' . $gateway->ttlSeconds . 'S')))
            ->withHeader('typ', self::TYPE)
            ->withHeader('kid', $this->keyId);

        $authTime = $this->authTime($claims['auth_time'] ?? null, $now);

        if ($authTime !== null) {
            $builder = $builder->withClaim('auth_time', $authTime);
        }

        $amr = $this->amr(self::texts($claims['amr'] ?? null));

        if ($amr !== []) {
            $builder = $builder->withClaim('amr', $amr);
        }

        // Entra's "acrs" is an array of authentication context IDs. Only well-formed IDs are passed on.
        $acrs = array_values(array_filter(self::texts($claims['acrs'] ?? null), static fn(string $value): bool => preg_match('/^c[1-9][0-9]?$/', $value) === 1));

        if ($acrs !== []) {
            $builder = $builder->withClaim('acrs', $acrs);
        }

        foreach (['email', 'name'] as $attribute) {
            $claim = $oidc->attributeClaims[$attribute] ?? null;
            $value = $claim === null ? null : self::text($claims[$claim] ?? null);

            if ($value !== null) {
                $builder = $builder->withClaim($attribute, $value);
            }
        }

        $claim = $oidc->attributeClaims['affiliations'] ?? null;
        $affiliations = $claim === null ? [] : self::texts($claims[$claim] ?? null);

        if ($affiliations !== []) {
            $builder = $builder->withClaim('affiliations', $affiliations);
        }

        foreach ($oidc->extraClaims as $name) {
            $value = self::extra($claims[$name] ?? null);

            if ($value !== null) {
                /** @var non-empty-string $name */
                $builder = $builder->withClaim($name, $value);
            }
        }

        return $builder->getToken(new Sha256(), $this->privateKey)->toString();
    }

    /**
     * The sign-in time as epoch seconds, or null when the ID token did not say or the value is not believable.
     *
     * A time in the future is dropped, never trusted: an assertion that claims a
     * sign-in that has not happened yet must not satisfy an "MFA within N minutes" rule.
     */
    private function authTime(mixed $value, DateTimeImmutable $now): ?int
    {
        if (! is_int($value) || $value < 0 || $value > $now->getTimestamp() + 60) {
            return null;
        }

        return $value;
    }

    /**
     * The provider's amr values through the configured map, in map order, without duplicates.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function amr(array $values): array
    {
        $out = [];

        foreach ($this->config->oidc->amrMap as $from => $to) {
            if (in_array((string) $from, $values, true)) {
                foreach ($to as $value) {
                    $out[$value] = true;
                }
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * A claim value as one line of text, or null when it is missing, not a string, too long or has control characters.
     *
     * @return non-empty-string|null
     */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_TEXT || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * A claim that is a list of strings (or one string). Anything unusable inside it is dropped.
     *
     * @return list<string>
     */
    private static function texts(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $out = [];

        foreach (array_slice($value, 0, self::MAX_LIST) as $item) {
            $text = self::text($item);

            if ($text !== null && ! in_array($text, $out, true)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * An extra claim, passed on as it is when it is a string, a number, a boolean or a list of those, and small.
     */
    private static function extra(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::text($value);
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $out = [];

        foreach (array_slice($value, 0, self::MAX_LIST) as $item) {
            if (is_string($item)) {
                $item = self::text($item);
            } elseif (! is_int($item) && ! is_float($item) && ! is_bool($item)) {
                $item = null;
            }

            if ($item !== null) {
                $out[] = $item;
            }
        }

        $json = json_encode($out);

        return ($out === [] || $json === false || strlen($json) > self::MAX_EXTRA_JSON) ? null : $out;
    }

    /**
     * @return non-empty-string
     */
    private static function nonEmpty(string $value): string
    {
        if ($value === '') {
            throw new InvalidSigningRequest('A required claim is empty.');
        }

        return $value;
    }
}
