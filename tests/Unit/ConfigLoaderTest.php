<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Config\ConfigException;
use Waynestate\Drawbridge\Config\ConfigLoader;
use Waynestate\Drawbridge\Config\Protection;
use Waynestate\Drawbridge\Tests\Support\Fixtures;

final class ConfigLoaderTest extends TestCase
{
    public function testAValidConfigLoadsWithSafeDefaults(): void
    {
        $config = Fixtures::config();

        $this->assertCount(2, $config->apps);
        $this->assertSame('X-Sesame-Assertion', $config->gateway->assertionHeader);
        $this->assertSame(60, $config->gateway->ttlSeconds);
        $this->assertSame(Protection::Login, $config->apps[0]->default);
        $this->assertSame('echo.apps.example.test', $config->apps[0]->audience);
        $this->assertSame('assertion', $config->apps[0]->mode);
        $this->assertSame('NAME_ID', $config->gateway->attributes['sub']);
    }

    public function testTheExampleConfigIsValid(): void
    {
        $config = ConfigLoader::fromFile(__DIR__ . '/../../config/drawbridge.example.yaml');

        $this->assertSame('app1', $config->apps[0]->id);
        $this->assertSame(Protection::Api, $config->apps[0]->protectionFor('/api/x'));
    }

    public function testAPhpArrayFileIsAccepted(): void
    {
        $file = sys_get_temp_dir() . '/drawbridge-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, '<?php return ' . var_export(Fixtures::configArray(), true) . ';');

        try {
            $this->assertCount(2, ConfigLoader::fromFile($file)->apps);
        } finally {
            unlink($file);
        }
    }

    public function testAppForHostIsExactAndIgnoresCaseAndPort(): void
    {
        $config = Fixtures::config();

        $this->assertSame('echo', $config->appForHost('ECHO.apps.example.test:8080')?->id);
        $this->assertNull($config->appForHost('evil.echo.apps.example.test'));
        $this->assertNull($config->appForHost('echo.apps.example.test.evil.test'));
        $this->assertNull($config->appForHost(''));
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function brokenConfigs(): array
    {
        return [
            'unknown top-level key' => [static fn(array $c): array => $c + ['extras' => 1], '"extras" is not a known key'],
            'unknown app key (a typo must not become public)' => [static function (array $c): array {
                $c['apps'][0]['publik'] = ['/x'];

                return $c;
            }, '"publik" is not a known key'],
            'no apps' => [static function (array $c): array {
                $c['apps'] = [];

                return $c;
            }, '"apps" must be a non-empty list'],
            'bad issuer' => [static function (array $c): array {
                $c['gateway']['issuer'] = 'javascript:alert(1)';

                return $c;
            }, 'gateway.issuer'],
            'bad host' => [static function (array $c): array {
                $c['apps'][0]['host'] = 'Echo.Example.Test:8080';

                return $c;
            }, 'host must be a lowercase DNS name'],
            'duplicate host' => [static function (array $c): array {
                $c['apps'][1]['host'] = $c['apps'][0]['host'];

                return $c;
            }, 'the host "echo.apps.example.test" is used twice'],
            'duplicate id' => [static function (array $c): array {
                $c['apps'][1]['id'] = 'echo';

                return $c;
            }, 'the id "echo" is used twice'],
            'upstream with a path' => [static function (array $c): array {
                $c['apps'][0]['upstream'] = 'http://echo:8080/base';

                return $c;
            }, 'upstream must look like'],
            'upstream with credentials' => [static function (array $c): array {
                $c['apps'][0]['upstream'] = 'http://user:pw@echo:8080';

                return $c;
            }, 'upstream must look like'],
            'upstream scheme' => [static function (array $c): array {
                $c['apps'][0]['upstream'] = 'file:///etc/passwd';

                return $c;
            }, 'upstream must look like'],
            'regex in a path' => [static function (array $c): array {
                $c['apps'][0]['public'] = ['/(a|b)/'];

                return $c;
            }, 'no wildcards, regular expressions'],
            'wildcard in a path' => [static function (array $c): array {
                $c['apps'][0]['public'] = ['/assets/*'];

                return $c;
            }, 'no wildcards'],
            'query string in a path' => [static function (array $c): array {
                $c['apps'][0]['public'] = ['/x?y=1'];

                return $c;
            }, 'no wildcards'],
            'encoded character in a path' => [static function (array $c): array {
                $c['apps'][0]['public'] = ['/%2e%2e/'];

                return $c;
            }, 'no wildcards'],
            'path without a leading slash' => [static function (array $c): array {
                $c['apps'][0]['public'] = ['assets/'];

                return $c;
            }, 'must start with "/"'],
            'dot dot in a path' => [static function (array $c): array {
                $c['apps'][0]['public'] = ['/a/../b'];

                return $c;
            }, 'is not a normalized path'],
            'double slash in a path' => [static function (array $c): array {
                $c['apps'][0]['public'] = ['/a//b'];

                return $c;
            }, 'is not a normalized path'],
            'the same path both public and protected' => [static function (array $c): array {
                $c['apps'][0]['public'] = ['/x/'];
                $c['apps'][0]['protected'] = ['/x/'];

                return $c;
            }, 'listed under both'],
            'a path inside the gateway reserved area' => [static function (array $c): array {
                $c['apps'][0]['public'] = ['/_drawbridge/sso/'];

                return $c;
            }, 'which the gateway keeps for itself'],
            'default is neither login nor public' => [static function (array $c): array {
                $c['apps'][0]['default'] = 'open';

                return $c;
            }, 'default must be "login" or "public"'],
            'mode is not known' => [static function (array $c): array {
                $c['apps'][0]['mode'] = 'bare';

                return $c;
            }, 'mode must be'],
            'ttl too long' => [static function (array $c): array {
                $c['gateway']['assertion_ttl_seconds'] = 3600;

                return $c;
            }, 'assertion_ttl_seconds'],
            'header name with a newline' => [static function (array $c): array {
                $c['gateway']['assertion_header'] = "X-A\r\nX-B";

                return $c;
            }, 'assertion_header'],
            'unknown attribute' => [static function (array $c): array {
                $c['gateway']['attributes']['role'] = 'x';

                return $c;
            }, '"role" is not one of sub, email, name, affiliations'],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $break
     */
    #[DataProvider('brokenConfigs')]
    public function testABrokenConfigIsRefusedWithAClearMessage(callable $break, string $expected): void
    {
        try {
            ConfigLoader::fromArray($break(Fixtures::configArray()));
            $this->fail('The config should have been refused.');
        } catch (ConfigException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testEveryProblemIsReportedTogether(): void
    {
        $config = Fixtures::configArray();
        $config['apps'][0]['host'] = 'BAD HOST';
        $config['apps'][0]['upstream'] = 'nope';
        $config['gateway']['issuer'] = 'nope';

        try {
            ConfigLoader::fromArray($config);
            $this->fail('The config should have been refused.');
        } catch (ConfigException $e) {
            $this->assertGreaterThanOrEqual(3, count($e->errors));
        }
    }

    public function testAMissingFileIsAConfigError(): void
    {
        $this->expectException(ConfigException::class);

        ConfigLoader::fromFile('/no/such/file.yaml');
    }
}
