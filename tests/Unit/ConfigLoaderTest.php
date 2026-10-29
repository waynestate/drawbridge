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
        $this->assertSame('preferred_username', $config->oidc->userClaim);
        $this->assertSame(['email' => 'email', 'name' => 'name', 'affiliations' => 'affiliations'], $config->oidc->attributeClaims);
        $this->assertSame(['mfa'], $config->oidc->amrMap['mfa']);
        $this->assertSame(Fixtures::OIDC_ISSUER . '/.well-known/openid-configuration', $config->oidc->metadataUrl);
    }

    public function testAnAccessIdUserClaimLoads(): void
    {
        $config = Fixtures::configArray();
        $config['oidc']['user_claim'] = 'accessid';

        $this->assertSame('accessid', ConfigLoader::fromArray($config)->oidc->userClaim);
    }

    public function testARuleCanNeedAnAuthenticationContext(): void
    {
        $app = Fixtures::config()->apps[0];

        $this->assertSame('c1', $app->ruleFor('/admin/vault/x')?->authContext);
        $this->assertNull($app->ruleFor('/admin/other')?->authContext);
        $this->assertSame(Protection::Login, $app->protectionFor('/admin/vault/x'));
        $this->assertSame('c2', $app->ruleFor('/api/vault/x')?->authContext);
        $this->assertSame(Protection::Api, $app->protectionFor('/api/vault/x'));
    }

    public function testAnAffiliationsClaimIsOffUnlessConfigured(): void
    {
        $config = Fixtures::configArray();
        unset($config['oidc']['attribute_claims']);

        $this->assertArrayNotHasKey('affiliations', ConfigLoader::fromArray($config)->oidc->attributeClaims);
    }

    public function testAnAttributeCanBeSwitchedOff(): void
    {
        $config = Fixtures::configArray();
        $config['oidc']['attribute_claims'] = ['name' => null];

        $this->assertArrayNotHasKey('name', ConfigLoader::fromArray($config)->oidc->attributeClaims);
    }

    public function testAnHttpIssuerIsAcceptedOnlyForATestHost(): void
    {
        $config = Fixtures::configArray();
        $config['oidc']['issuer'] = 'http://idp.test:8080/entra';

        $this->assertSame('http://idp.test:8080/entra', ConfigLoader::fromArray($config)->oidc->issuer);
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
            'a SAML-era key' => [static function (array $c): array {
                $c['gateway']['sp_entity_id'] = 'https://auth.apps.example.test/metadata';

                return $c;
            }, '"sp_entity_id" is not a known key'],
            'no oidc block' => [static function (array $c): array {
                unset($c['oidc']);

                return $c;
            }, '"oidc" must be a map'],
            'unknown oidc key' => [static function (array $c): array {
                $c['oidc']['client_secret'] = 'oops';

                return $c;
            }, '"client_secret" is not a known key'],
            'plain http issuer' => [static function (array $c): array {
                $c['oidc']['issuer'] = 'http://login.microsoftonline.com/x/v2.0';

                return $c;
            }, 'must use https'],
            'issuer with a query' => [static function (array $c): array {
                $c['oidc']['issuer'] = 'https://login.example.test/?tenant=x';

                return $c;
            }, 'oidc.issuer'],
            'no client id' => [static function (array $c): array {
                unset($c['oidc']['client_id']);

                return $c;
            }, 'oidc.client_id'],
            'no client secret env' => [static function (array $c): array {
                unset($c['oidc']['client_secret_env']);

                return $c;
            }, 'oidc.client_secret_env'],
            'a secret where the env name goes' => [static function (array $c): array {
                $c['oidc']['client_secret_env'] = 'abc~DEF.ghi_123';

                return $c;
            }, 'oidc.client_secret_env'],
            'a bad user claim' => [static function (array $c): array {
                $c['oidc']['user_claim'] = 'preferred username';

                return $c;
            }, 'oidc.user_claim'],
            'unknown attribute' => [static function (array $c): array {
                $c['oidc']['attribute_claims']['role'] = 'x';

                return $c;
            }, '"role" is not one of email, name, affiliations'],
            'an extra claim that the assertion owns' => [static function (array $c): array {
                $c['oidc']['extra_claims'] = ['sub'];

                return $c;
            }, 'is a claim the assertion sets itself'],
            'an amr map with a bad value' => [static function (array $c): array {
                $c['oidc']['amr_map'] = ['mfa' => ['MFA!']];

                return $c;
            }, 'oidc.amr_map'],
            'an auth context on a public path' => [static function (array $c): array {
                $c['apps'][0]['public'][] = ['path' => '/open/', 'auth_context' => 'c1'];

                return $c;
            }, 'a public path cannot need an authentication context'],
            'an auth context that is not c1 to c99' => [static function (array $c): array {
                $c['apps'][0]['protected'][] = ['path' => '/x/', 'auth_context' => 'c100'];

                return $c;
            }, 'auth_context must be'],
            'an unknown key in a rule' => [static function (array $c): array {
                $c['apps'][0]['protected'][] = ['path' => '/x/', 'auth_contxt' => 'c1'];

                return $c;
            }, '"auth_contxt" is not a known key'],
            'a rule map without a path' => [static function (array $c): array {
                $c['apps'][0]['protected'][] = ['auth_context' => 'c1'];

                return $c;
            }, 'every path must be a non-empty string'],
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
