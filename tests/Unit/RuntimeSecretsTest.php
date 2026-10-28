<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Render\RuntimeSecrets;
use Waynestate\Drawbridge\Tests\Support\Fixtures;

final class RuntimeSecretsTest extends TestCase
{
    private const SECRET = 'test-only~client.secret_value';

    private const PASSPHRASE = 'test-only-passphrase-0123456789abcdef';

    /**
     * @param  array<string, string|false>  $override
     * @return list<string>
     */
    private function problems(array $override = []): array
    {
        return RuntimeSecrets::problems(Fixtures::config(), array_merge([
            'DRAWBRIDGE_OIDC_CLIENT_SECRET' => self::SECRET,
            'DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE' => self::PASSPHRASE,
        ], $override));
    }

    public function testBothSetIsFine(): void
    {
        $this->assertSame([], $this->problems());
    }

    public function testAMissingClientSecretStopsTheGateway(): void
    {
        $problems = $this->problems(['DRAWBRIDGE_OIDC_CLIENT_SECRET' => false]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('DRAWBRIDGE_OIDC_CLIENT_SECRET is not set', $problems[0]);
    }

    public function testAnEmptyPassphraseStopsTheGateway(): void
    {
        $this->assertStringContainsString('DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE is not set', $this->problems(['DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE' => ''])[0]);
    }

    public function testAShortPassphraseStopsTheGateway(): void
    {
        $this->assertStringContainsString('shorter than 32', $this->problems(['DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE' => 'short'])[0]);
    }

    public function testAQuoteOrSpaceStopsTheGateway(): void
    {
        $this->assertNotSame([], $this->problems(['DRAWBRIDGE_OIDC_CLIENT_SECRET' => 'abc" Require all granted "']));
        $this->assertNotSame([], $this->problems(['DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE' => str_repeat('a', 40) . ' b']));
        $this->assertNotSame([], $this->problems(['DRAWBRIDGE_OIDC_CLIENT_SECRET' => 'abc\def']));
    }

    public function testTheMessagesNeverIncludeTheValue(): void
    {
        $value = 'Not"Allowed' . str_repeat('x', 40);

        foreach ($this->problems(['DRAWBRIDGE_OIDC_CLIENT_SECRET' => $value, 'DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE' => 'tooshort']) as $problem) {
            $this->assertStringNotContainsString($value, $problem);
            $this->assertStringNotContainsString('tooshort', $problem);
        }
    }

    public function testTheSecretVariableNameComesFromTheConfig(): void
    {
        $config = Fixtures::configArray();
        $config['oidc']['client_secret_env'] = 'OTHER_SECRET';
        $problems = RuntimeSecrets::problems(\Waynestate\Drawbridge\Config\ConfigLoader::fromArray($config), [
            'DRAWBRIDGE_OIDC_CLIENT_SECRET' => self::SECRET,
            'DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE' => self::PASSPHRASE,
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('OTHER_SECRET', $problems[0]);
    }
}
