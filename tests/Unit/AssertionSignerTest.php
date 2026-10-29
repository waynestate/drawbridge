<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Assertion\AssertionSigner;
use Waynestate\Drawbridge\Assertion\InvalidSigningRequest;
use Waynestate\Drawbridge\Assertion\KeyPair;
use Waynestate\Drawbridge\Assertion\SigningRequest;
use Waynestate\Drawbridge\Config\ConfigLoader;
use Waynestate\Drawbridge\Tests\Support\FixedClock;
use Waynestate\Drawbridge\Tests\Support\Fixtures;
use Waynestate\Drawbridge\Tests\Support\SesameContract;

final class AssertionSignerTest extends TestCase
{
    private const NOW = '2026-10-28 12:00:00 UTC';

    private const ISSUER = 'https://auth.apps.example.test';

    /** @var array{private: string, public: string} */
    private array $keys;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->keys = KeyPair::generate();
        $this->clock = FixedClock::at(self::NOW);
    }

    private function signer(?\Waynestate\Drawbridge\Config\Config $config = null): AssertionSigner
    {
        return new AssertionSigner($config ?? Fixtures::config(), InMemory::plainText($this->keys['private']), 'test-key-1', $this->clock);
    }

    private function now(): int
    {
        return (new \DateTimeImmutable(self::NOW))->getTimestamp();
    }

    /**
     * An Entra-shaped ID token payload, as mod_auth_openidc hands it over.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function idToken(array $override = []): array
    {
        return array_merge([
            'iss' => Fixtures::OIDC_ISSUER,
            'aud' => '11111111-2222-3333-4444-555555555555',
            'sub' => 'AAAAAAAAAAAAAAAAAAAAAIkzqFVrSaSaFHy782bbtaQ',
            'oid' => '00000000-0000-0000-66f3-3332eca7ea81',
            'preferred_username' => 'jdoe@example.test',
            'email' => 'jdoe@example.test',
            'name' => 'Jane Doe',
            'affiliations' => ['student', 'member'],
            'auth_time' => $this->now() - 300,
            'amr' => ['pwd', 'mfa'],
            'acrs' => ['c1', 'c3'],
            'wsu_roles' => ['staff', 'faculty'],
            'iat' => $this->now() - 300,
            'exp' => $this->now() + 3300,
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function request(string $host = 'echo.apps.example.test', array $override = []): SigningRequest
    {
        return SigningRequest::fromLine(SigningRequest::toLine($host, $this->idToken($override)));
    }

    private function parse(string $token): UnencryptedToken
    {
        $parsed = (new Parser(new JoseEncoder()))->parse($token);
        $this->assertInstanceOf(UnencryptedToken::class, $parsed);

        return $parsed;
    }

    public function testTheTokenSatisfiesWhatSesameChecks(): void
    {
        $token = $this->signer()->sign($this->request());

        $claims = SesameContract::accepts($token, $this->keys['public'], self::ISSUER, 'echo.apps.example.test', $this->clock);

        $this->assertNotNull($claims);
        $this->assertSame('jdoe@example.test', $claims['sub'], 'sub is the configured user claim, not the pairwise Entra sub');
        $this->assertSame('jdoe@example.test', $claims['email']);
        $this->assertSame('Jane Doe', $claims['name']);
        $this->assertSame(['student', 'member'], $claims['affiliations']);
    }

    public function testTheTokenCarriesTheDocumentedClaims(): void
    {
        $parsed = $this->parse($this->signer()->sign($this->request()));
        $claims = $parsed->claims();
        $now = $this->now();

        $this->assertSame(self::ISSUER, $claims->get('iss'), 'iss is DRAWBRIDGE, never the provider');
        $this->assertSame(['echo.apps.example.test'], $claims->get('aud'));
        $this->assertSame($now, $claims->get('iat')->getTimestamp());
        $this->assertSame($now + 60, $claims->get('exp')->getTimestamp());
        $this->assertSame($now - 300, $claims->get('auth_time'));
        $this->assertSame(['pwd', 'mfa'], $claims->get('amr'));
        $this->assertSame(['c1', 'c3'], $claims->get('acrs'));
        $this->assertSame(['staff', 'faculty'], $claims->get('wsu_roles'));
        $this->assertFalse($claims->has('oid'), 'claims that are not configured are not passed on');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $claims->get('jti'));
        $this->assertSame('drawbridge+jwt', $parsed->headers()->get('typ'));
        $this->assertSame('test-key-1', $parsed->headers()->get('kid'));
        $this->assertSame('ES256', $parsed->headers()->get('alg'));
    }

    public function testEveryTokenHasItsOwnJti(): void
    {
        $first = $this->parse($this->signer()->sign($this->request()))->claims()->get('jti');
        $second = $this->parse($this->signer()->sign($this->request()))->claims()->get('jti');

        $this->assertNotSame($first, $second);
    }

    public function testTheAudienceIsTheAppNotWhateverTheRequestSays(): void
    {
        $token = $this->signer()->sign($this->request('blog.apps.example.test'));

        $this->assertNull(SesameContract::accepts($token, $this->keys['public'], self::ISSUER, 'echo.apps.example.test', $this->clock));
        $this->assertNotNull(SesameContract::accepts($token, $this->keys['public'], self::ISSUER, 'blog.apps.example.test', $this->clock));
    }

    public function testAHostThatIsNotConfiguredIsNotSigned(): void
    {
        $this->expectException(InvalidSigningRequest::class);

        $this->signer()->sign($this->request('evil.example.test'));
    }

    public function testAnIdTokenFromAnotherIssuerIsNotSigned(): void
    {
        $this->expectException(InvalidSigningRequest::class);

        $this->signer()->sign($this->request(override: ['iss' => 'https://login.microsoftonline.com/99999999-0000-0000-0000-000000000000/v2.0']));
    }

    public function testAnIdTokenWithoutTheUserClaimIsNotSigned(): void
    {
        $this->expectException(InvalidSigningRequest::class);

        $this->signer()->sign($this->request(override: ['preferred_username' => '']));
    }

    public function testAUserClaimWithAControlCharacterIsNotSigned(): void
    {
        $this->expectException(InvalidSigningRequest::class);

        $this->signer()->sign($this->request(override: ['preferred_username' => "jdoe\nadmin"]));
    }

    public function testTheUserClaimIsConfigurable(): void
    {
        $config = Fixtures::configArray();
        $config['oidc']['user_claim'] = 'alias';
        $token = $this->signer(ConfigLoader::fromArray($config))->sign($this->request(override: ['alias' => 'ab1234']));

        $this->assertSame('ab1234', $this->parse($token)->claims()->get('sub'));
    }

    public function testAnotherKeyOrAnotherIssuerOrAnExpiredTokenIsRefused(): void
    {
        $token = $this->signer()->sign($this->request());
        $other = KeyPair::generate();

        $this->assertNull(SesameContract::accepts($token, $other['public'], self::ISSUER, 'echo.apps.example.test', $this->clock));
        $this->assertNull(SesameContract::accepts($token, $this->keys['public'], 'https://other.example.test', 'echo.apps.example.test', $this->clock));

        $this->clock->advance('+91 seconds');
        $this->assertNull(SesameContract::accepts($token, $this->keys['public'], self::ISSUER, 'echo.apps.example.test', $this->clock));
    }

    public function testATamperedTokenIsRefused(): void
    {
        [$header, $payload, $signature] = explode('.', $this->signer()->sign($this->request()));
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertIsArray($claims);
        $claims['sub'] = 'admin';
        $forged = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

        $this->assertNull(SesameContract::accepts("{$header}.{$forged}.{$signature}", $this->keys['public'], self::ISSUER, 'echo.apps.example.test', $this->clock));
    }

    public function testNoAuthTimeAmrOrAcrsWhenTheIdTokenDidNotSayAnything(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(override: ['auth_time' => null, 'amr' => null, 'acrs' => null])))->claims();

        $this->assertFalse($claims->has('auth_time'));
        $this->assertFalse($claims->has('amr'));
        $this->assertFalse($claims->has('acrs'));
    }

    public function testAPasswordOnlySignInIsNotMfa(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(override: ['amr' => ['pwd']])))->claims();

        $this->assertSame(['pwd'], $claims->get('amr'));
    }

    public function testUnknownAmrValuesGiveNoAmr(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(override: ['amr' => ['made-up', 'mfa-ish']])))->claims();

        $this->assertFalse($claims->has('amr'));
    }

    public function testNgcmfaCountsAsMfaOnce(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(override: ['amr' => ['ngcmfa', 'mfa']])))->claims();

        $this->assertSame(['mfa'], $claims->get('amr'));
    }

    public function testOnlyWellFormedAuthenticationContextsArePassedOn(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(override: ['acrs' => ['c1', 'admin', 'c100', 'c0', 7]])))->claims();

        $this->assertSame(['c1'], $claims->get('acrs'));
    }

    public function testAnAuthTimeInTheFutureIsDropped(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(override: ['auth_time' => $this->now() + 3600])))->claims();

        $this->assertFalse($claims->has('auth_time'));
    }

    public function testANonNumericAuthTimeIsDropped(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(override: ['auth_time' => '28 October 2026'])))->claims();

        $this->assertFalse($claims->has('auth_time'));
    }

    public function testAnExtraClaimThatIsAnObjectIsDropped(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(override: ['wsu_roles' => ['nested' => ['x' => 1]]])))->claims();

        $this->assertFalse($claims->has('wsu_roles'));
    }

    public function testAnAffiliationsClaimThatIsOneStringBecomesAList(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(override: ['affiliations' => 'staff'])))->claims();

        $this->assertSame(['staff'], $claims->get('affiliations'));
    }
}
