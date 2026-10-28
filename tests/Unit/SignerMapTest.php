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

    public function testAGoodLineGetsAToken(): void
    {
        $answer = $this->map()->handle(SigningRequest::toLine('echo.apps.example.test', 'jdoe'));

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
            'wrong version' => ['v2.YQ==.YQ==.YQ==.YQ==.YQ==.YQ==.YQ=='],
            'too few fields' => ['v1.YQ==.YQ=='],
            'not base64' => ['v1.!!!.YQ==.YQ==.YQ==.YQ==.YQ==.YQ=='],
            'no subject' => [SigningRequest::toLine('echo.apps.example.test', '')],
            'host not configured' => [SigningRequest::toLine('evil.example.test', 'jdoe')],
            'newline in the subject' => [SigningRequest::toLine('echo.apps.example.test', "jdoe\nadmin")],
            'control character in the name' => [SigningRequest::toLine('echo.apps.example.test', 'jdoe', '', "Jane\x00Doe")],
            'invalid UTF-8' => [SigningRequest::toLine('echo.apps.example.test', "\xff\xfe")],
            'oversize field' => [SigningRequest::toLine('echo.apps.example.test', str_repeat('a', 600))],
            'oversize line' => ['v1.' . str_repeat('A', 9000)],
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

        fwrite($input, SigningRequest::toLine('echo.apps.example.test', 'a') . "\n");
        fwrite($input, "garbage\n");
        fwrite($input, SigningRequest::toLine('blog.apps.example.test', 'b') . "\n");
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
            SigningRequest::fromLine(SigningRequest::toLine('echo.apps.example.test', "secret-subject\n"));
            $this->fail('Expected a refusal.');
        } catch (InvalidSigningRequest $e) {
            $this->assertStringNotContainsString('secret-subject', $e->getMessage());
        }
    }
}
