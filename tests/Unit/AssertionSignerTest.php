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

    private function signer(): AssertionSigner
    {
        return new AssertionSigner(Fixtures::config(), InMemory::plainText($this->keys['private']), 'test-key-1', $this->clock);
    }

    private function request(string $host = 'echo.apps.example.test', string $authInstant = '2026-10-28T11:55:00Z', string $context = 'https://refeds.org/profile/mfa'): SigningRequest
    {
        return SigningRequest::fromLine(SigningRequest::toLine($host, 'jdoe', 'jdoe@example.test', 'Jane Doe', ['student', 'member'], $authInstant, $context));
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
        $this->assertSame('jdoe', $claims['sub']);
        $this->assertSame('jdoe@example.test', $claims['email']);
        $this->assertSame('Jane Doe', $claims['name']);
        $this->assertSame(['student', 'member'], $claims['affiliations']);
    }

    public function testTheTokenCarriesTheDocumentedClaims(): void
    {
        $parsed = $this->parse($this->signer()->sign($this->request()));
        $claims = $parsed->claims();
        $now = (new \DateTimeImmutable(self::NOW))->getTimestamp();

        $this->assertSame(self::ISSUER, $claims->get('iss'));
        $this->assertSame(['echo.apps.example.test'], $claims->get('aud'));
        $this->assertSame($now, $claims->get('iat')->getTimestamp());
        $this->assertSame($now + 60, $claims->get('exp')->getTimestamp());
        $this->assertSame($now - 300, $claims->get('auth_time'));
        $this->assertSame(['mfa'], $claims->get('amr'));
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

    public function testNoAuthTimeOrAmrWhenTheIdpDidNotSayAnything(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(authInstant: '', context: '')))->claims();

        $this->assertFalse($claims->has('auth_time'));
        $this->assertFalse($claims->has('amr'));
    }

    public function testAnUnknownAuthnContextGivesNoAmr(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(context: 'urn:example:made-up')))->claims();

        $this->assertFalse($claims->has('amr'));
    }

    public function testAnAuthTimeInTheFutureIsDropped(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(authInstant: '2026-10-28T13:00:00Z')))->claims();

        $this->assertFalse($claims->has('auth_time'));
    }

    public function testAMalformedAuthInstantIsDropped(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(authInstant: '28 October 2026')))->claims();

        $this->assertFalse($claims->has('auth_time'));
    }

    public function testFractionalSecondsInAuthInstantAreAccepted(): void
    {
        $claims = $this->parse($this->signer()->sign($this->request(authInstant: '2026-10-28T11:55:00.123Z')))->claims();

        $this->assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp() - 300, $claims->get('auth_time'));
    }
}
