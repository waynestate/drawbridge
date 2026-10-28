<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Assertion;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
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
 * Claims: iss, aud (the one app's audience), sub, iat, nbf, exp, a unique
 * jti, auth_time and amr when the IdP gave enough to say, and the optional
 * email, name and affiliations that SESAME turns into attributes. The
 * header carries a key ID for rotation (D7) and its own type, so the token
 * cannot pass as an OAuth2 access token (D8).
 *
 * The audience comes from the gateway config, looked up by Host. The signer
 * never takes an audience from the request line.
 */
final class AssertionSigner
{
    public const TYPE = 'drawbridge+jwt';

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

        $gateway = $this->config->gateway;
        $now = $this->clock->now();

        $builder = (new Builder(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates()))
            ->issuedBy(self::nonEmpty($gateway->issuer))
            ->permittedFor(self::nonEmpty($app->audience))
            ->relatedTo(self::nonEmpty($request->subject))
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->add(new DateInterval('PT' . $gateway->ttlSeconds . 'S')))
            ->withHeader('typ', self::TYPE)
            ->withHeader('kid', $this->keyId);

        $authTime = $this->authTime($request->authInstant, $now);

        if ($authTime !== null) {
            $builder = $builder->withClaim('auth_time', $authTime);
        }

        $amr = $gateway->amrMap[$request->authnContext] ?? [];

        if ($amr !== []) {
            $builder = $builder->withClaim('amr', $amr);
        }

        if ($request->email !== '') {
            $builder = $builder->withClaim('email', $request->email);
        }

        if ($request->name !== '') {
            $builder = $builder->withClaim('name', $request->name);
        }

        if ($request->affiliations !== []) {
            $builder = $builder->withClaim('affiliations', $request->affiliations);
        }

        return $builder->getToken(new Sha256(), $this->privateKey)->toString();
    }

    /**
     * The sign-in time as epoch seconds, or null when the IdP did not say or the value is not believable.
     *
     * A time in the future is dropped, never trusted: an assertion that claims a
     * sign-in that has not happened yet must not satisfy an "MFA within N minutes" rule.
     */
    private function authTime(string $authInstant, DateTimeImmutable $now): ?int
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(\.\d+)?Z$/', $authInstant, $match) !== 1) {
            return null;
        }

        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $match[1], new DateTimeZone('UTC'));

        if ($time === false) {
            return null;
        }

        $seconds = $time->getTimestamp();

        if ($seconds < 0 || $seconds > $now->getTimestamp() + 60) {
            return null;
        }

        return $seconds;
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
