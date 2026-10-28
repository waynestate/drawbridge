<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Integration;

use DateTimeImmutable;
use JsonException;
use Lcobucci\JWT\Signer\Key\InMemory;
use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Assertion\AssertionSigner;
use Waynestate\Drawbridge\Assertion\KeyPair;
use Waynestate\Drawbridge\Assertion\SigningRequest;
use Waynestate\Drawbridge\Tests\Support\FixedClock;
use Waynestate\Drawbridge\Tests\Support\Fixtures;

/**
 * The signed assertion against SESAME's real GatewayDriver, in a child process.
 *
 * The unit suite checks the signer's output against a mirror of the driver's
 * rules (tests/Support/SesameContract.php); this suite checks it against the
 * real class, so the mirror cannot drift silently. It is skipped unless
 * SESAME_PATH points at a SESAME checkout that has been installed
 * (composer install), because the two projects must not share a Composer
 * autoloader in one process: the token is signed here, and verified in
 * tests/Support/sesame-verify.php, which loads SESAME's autoloader alone.
 */
final class RealSesameTest extends TestCase
{
    private const NOW = '2026-10-28 12:00:00 UTC';

    private const ISSUER = 'https://auth.apps.example.test';

    private const AUDIENCE = 'echo.apps.example.test';

    private const PEER = '10.1.2.3';

    private const UNTRUSTED_PEER = '192.168.1.1';

    /** @var array{private: string, public: string} */
    private array $keys;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $path = getenv('SESAME_PATH');

        if ($path === false || $path === '' || ! is_file($path . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) {
            $this->markTestSkipped(
                'SESAME_PATH is not set to an installed SESAME checkout (vendor/autoload.php), so the real GatewayDriver is not exercised. '
                . 'Point SESAME_PATH at a SESAME checkout after running composer install in it.',
            );
        }

        $this->keys = KeyPair::generate();
        $this->clock = FixedClock::at(self::NOW);
    }

    public function testAGoodTokenPassesWithTheRightUser(): void
    {
        $result = $this->verify($this->description($this->sign()));

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? 'no error given'));
        $this->assertSame('jdoe@example.test', $result['user']);
    }

    public function testAnExpiredTokenFails(): void
    {
        // 91 seconds after signing: 31 past the 60-second expiry, over the 30-second leeway.
        $result = $this->verify($this->description($this->sign(), now: '2026-10-28 12:01:31 UTC'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('gateway.expired', (string) $result['error']);
    }

    public function testAWrongAudienceFails(): void
    {
        $result = $this->verify($this->description($this->sign('blog.apps.example.test')));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('gateway.audience', (string) $result['error']);
    }

    public function testAWrongKeyFails(): void
    {
        $other = KeyPair::generate();

        $result = $this->verify($this->description($this->sign(), publicKey: $other['public']));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('gateway.signature', (string) $result['error']);
    }

    public function testAWrongIssuerFails(): void
    {
        $result = $this->verify($this->description($this->sign(), issuer: 'https://other.example.test'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('gateway.issuer', (string) $result['error']);
    }

    public function testATamperedTokenFails(): void
    {
        [$header, $payload, $signature] = explode('.', $this->sign());
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertIsArray($claims);
        $claims['sub'] = 'admin';
        $forged = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

        $result = $this->verify($this->description("{$header}.{$forged}.{$signature}"));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('gateway.signature', (string) $result['error']);
    }

    public function testANonProxyPeerFails(): void
    {
        $result = $this->verify($this->description($this->sign(), peer: self::UNTRUSTED_PEER));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('gateway.untrusted_peer', (string) $result['error']);
    }

    /**
     * Sign an assertion for the given host with DRAWBRIDGE's real signer.
     *
     * @param  array<string, mixed>  $override
     */
    private function sign(string $host = 'echo.apps.example.test', array $override = []): string
    {
        $now = (new DateTimeImmutable(self::NOW))->getTimestamp();
        $claims = array_merge([
            'iss' => Fixtures::OIDC_ISSUER,
            'aud' => '11111111-2222-3333-4444-555555555555',
            'sub' => 'AAAAAAAAAAAAAAAAAAAAAIkzqFVrSaSaFHy782bbtaQ',
            'oid' => '00000000-0000-0000-66f3-3332eca7ea81',
            'preferred_username' => 'jdoe@example.test',
            'email' => 'jdoe@example.test',
            'name' => 'Jane Doe',
            'affiliations' => ['student', 'member'],
            'auth_time' => $now - 300,
            'amr' => ['pwd', 'mfa'],
            'acrs' => ['c1', 'c3'],
            'wsuRoles' => ['staff', 'faculty'],
            'iat' => $now - 300,
            'exp' => $now + 3300,
        ], $override);

        $signer = new AssertionSigner(Fixtures::config(), InMemory::plainText($this->keys['private']), 'test-key-1', $this->clock);

        return $signer->sign(SigningRequest::fromLine(SigningRequest::toLine($host, $claims)));
    }

    /**
     * The request description for sesame-verify.php.
     *
     * @return array<string, mixed>
     */
    private function description(string $token, string $peer = self::PEER, ?string $publicKey = null, string $issuer = self::ISSUER, string $audience = self::AUDIENCE, string $now = self::NOW): array
    {
        return [
            'token' => $token,
            'peer' => $peer,
            'public_key' => $publicKey ?? $this->keys['public'],
            'issuer' => $issuer,
            'audience' => $audience,
            'now' => $now,
        ];
    }

    /**
     * Run the real driver in a child process and read its JSON answer.
     *
     * @param  array<string, mixed>  $description
     * @return array{ok: bool, user?: string, error?: string}
     */
    private function verify(array $description): array
    {
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'sesame-verify.php';
        $json = json_encode($description, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);

        $process = proc_open(
            [PHP_BINARY, $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        $this->assertIsResource($process);

        fwrite($pipes[0], $json);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $this->assertSame(0, $exit, "sesame-verify.php exited with {$exit}: {$stderr}");

        try {
            $result = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail("sesame-verify.php did not answer with JSON: {$stdout} {$stderr}");
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertIsBool($result['ok']);

        return $result;
    }
}
