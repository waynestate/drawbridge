<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use Lcobucci\JWT\Signer\Key\InMemory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Assertion\AssertionSigner;
use Waynestate\Drawbridge\Assertion\InvalidSigningRequest;
use Waynestate\Drawbridge\Assertion\KeyPair;
use Waynestate\Drawbridge\Assertion\SignerMap;
use Waynestate\Drawbridge\Assertion\SigningRequest;
use Waynestate\Drawbridge\Tests\Support\FixedClock;
use Waynestate\Drawbridge\Tests\Support\Fixtures;

final class SignerMapTest extends TestCase
{
    private function map(): SignerMap
    {
        $keys = KeyPair::generate();

        return new SignerMap(new AssertionSigner(Fixtures::config(), InMemory::plainText($keys['private']), 'k', FixedClock::at('2026-10-28 12:00:00 UTC')), fopen('php://memory', 'w+'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function idToken(string $user = 'jdoe'): array
    {
        return ['iss' => Fixtures::OIDC_ISSUER, 'preferred_username' => $user];
    }

    public function testAGoodLineGetsAToken(): void
    {
        $answer = $this->map()->handle(SigningRequest::toLine('echo.apps.example.test', self::idToken()));

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $answer);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badLines(): array
    {
        return [
            'empty' => [''],
            'not our protocol' => ['hello'],
            'the old SAML-era version' => ['v1.YQ==.YQ==.YQ==.YQ==.YQ==.YQ==.YQ=='],
            'too few fields' => ['v2.YQ=='],
            'too many fields' => ['v2.YQ==.e30=.YQ=='],
            'not base64' => ['v2.!!!.e30='],
            'payload is not JSON' => ['v2.' . base64_encode('echo.apps.example.test') . '.' . base64_encode('not json')],
            'payload is a list' => ['v2.' . base64_encode('echo.apps.example.test') . '.' . base64_encode('["a"]')],
            'no subject' => [SigningRequest::toLine('echo.apps.example.test', self::idToken(''))],
            'no issuer' => [SigningRequest::toLine('echo.apps.example.test', ['preferred_username' => 'jdoe'])],
            'another issuer' => [SigningRequest::toLine('echo.apps.example.test', ['iss' => 'https://evil.example.test', 'preferred_username' => 'jdoe'])],
            'host not configured' => [SigningRequest::toLine('evil.example.test', self::idToken())],
            'newline in the subject' => [SigningRequest::toLine('echo.apps.example.test', self::idToken("jdoe\nadmin"))],
            'invalid UTF-8 in the host' => ['v2.' . base64_encode("\xff\xfe") . '.e30='],
            'oversize subject' => [SigningRequest::toLine('echo.apps.example.test', self::idToken(str_repeat('a', 600)))],
            'oversize payload' => [SigningRequest::toLine('echo.apps.example.test', self::idToken() + ['blob' => str_repeat('a', 20000)])],
            'oversize line' => ['v2.' . str_repeat('A', 30000)],
        ];
    }

    #[DataProvider('badLines')]
    public function testABadLineGetsNullNeverAToken(string $line): void
    {
        $this->assertSame('NULL', $this->map()->handle($line));
    }

    public function testServeAnswersOneLinePerRequest(): void
    {
        $input = fopen('php://memory', 'w+');
        $output = fopen('php://memory', 'w+');
        $this->assertIsResource($input);
        $this->assertIsResource($output);

        fwrite($input, SigningRequest::toLine('echo.apps.example.test', self::idToken('a')) . "\n");
        fwrite($input, "garbage\n");
        fwrite($input, SigningRequest::toLine('blog.apps.example.test', self::idToken('b')) . "\n");
        rewind($input);

        $this->map()->serve($input, $output);
        rewind($output);
        $answers = explode("\n", trim((string) stream_get_contents($output)));

        $this->assertCount(3, $answers);
        $this->assertNotSame('NULL', $answers[0]);
        $this->assertSame('NULL', $answers[1]);
        $this->assertNotSame('NULL', $answers[2]);
    }

    public function testAnInvalidRequestExceptionCarriesNoSecrets(): void
    {
        try {
            SigningRequest::fromLine('v2.' . base64_encode('echo.apps.example.test') . '.' . base64_encode('{"secret-subject": '));
            $this->fail('Expected a refusal.');
        } catch (InvalidSigningRequest $e) {
            $this->assertStringNotContainsString('secret-subject', $e->getMessage());
        }
    }
}
