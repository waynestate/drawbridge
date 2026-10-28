<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Render\ApacheRenderer;
use Waynestate\Drawbridge\Render\LuaPolicyRenderer;
use Waynestate\Drawbridge\Render\RenderOptions;
use Waynestate\Drawbridge\Tests\Support\Fixtures;

final class RenderTest extends TestCase
{
    private function options(bool $secure = true): RenderOptions
    {
        return new RenderOptions(configFile: '/etc/drawbridge/drawbridge.yaml', outputDir: '/tmp/out', secureCookies: $secure, trustedProxies: ['10.0.0.0/8']);
    }

    public function testEveryAppGetsItsOwnHostAndProxy(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());

        $this->assertStringContainsString('ServerName https://echo.apps.example.test', $conf);
        $this->assertStringContainsString('ServerName https://blog.apps.example.test', $conf);
        $this->assertStringContainsString('ProxyPass "/" "http://echo:8080/"', $conf);
        $this->assertStringContainsString('ProxyPass "/_drawbridge/" "!"', $conf);
        $this->assertStringContainsString('ProxyAddHeaders Off', $conf);
    }

    public function testUnknownHostsGetNothing(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());

        $this->assertMatchesRegularExpression('/ServerName drawbridge-default\.invalid.*?Require all denied/s', $conf);
    }

    public function testRulesBecomeLiteralPatternsLeastSpecificFirst(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());

        $admin = strpos($conf, '<LocationMatch "^/admin(/|$)">');
        $open = strpos($conf, '<LocationMatch "^/admin/open$">');

        $this->assertNotFalse($admin);
        $this->assertNotFalse($open);
        $this->assertGreaterThan($admin, $open, 'the exact rule must come after the prefix it overrides');
        $this->assertStringContainsString('<LocationMatch "^/api(/|$)">', $conf);
    }

    public function testEndpointSectionComesLast(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());
        $firstApp = (int) strpos($conf, '# App: echo');
        $nextApp = (int) strpos($conf, '# App: blog');
        $endpoint = (int) strpos($conf, '<Location "/_drawbridge/oidc">', $firstApp);
        $lastRule = (int) strrpos(substr($conf, $firstApp, $nextApp - $firstApp), '<LocationMatch');

        $this->assertGreaterThan($lastRule + $firstApp, $endpoint);
    }

    public function testProtectedSectionsRequireASessionAndSignFailClosed(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());

        $this->assertStringContainsString('AuthType openid-connect', $conf);
        $this->assertStringContainsString('OIDCUnAuthAction auth', $conf);
        $this->assertStringContainsString('Require valid-user', $conf);
        $this->assertStringContainsString('OIDCPassClaimsAs environment none', $conf);
        $this->assertStringContainsString('OIDCPassAccessToken Off', $conf);
        $this->assertStringContainsString('OIDCPassRefreshToken Off', $conf);
        $this->assertStringContainsString('RewriteRule ^ - [R=503,L]', $conf);
        $this->assertStringContainsString('RequestHeader set X-Sesame-Assertion "%{DRAWBRIDGE_ASSERTION}e" env=DRAWBRIDGE_ASSERTION', $conf);
    }

    public function testPublicSectionsAskForNothingAndSignNothing(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());
        $start = (int) strpos($conf, '<LocationMatch "^/up$">');
        $block = substr($conf, $start, (int) strpos($conf, '</LocationMatch>', $start) - $start);

        $this->assertStringContainsString('Require all granted', $block);
        $this->assertStringContainsString('OIDCUnAuthAction pass', $block);
        $this->assertStringNotContainsString('RequestHeader', $block);
        $this->assertStringNotContainsString('Require valid-user', $block);
    }

    public function testApiPathsAnswer401NotARedirect(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());
        $start = (int) strpos($conf, '<LocationMatch "^/api(/|$)">');
        $block = substr($conf, $start, (int) strpos($conf, '</LocationMatch>', $start) - $start);

        $this->assertStringContainsString('OIDCUnAuthAction 401', $block);
        $this->assertStringContainsString('Require valid-user', $block);
    }

    public function testSecureCookiesGetTheHostPrefix(): void
    {
        $secure = (new ApacheRenderer())->render(Fixtures::config(), $this->options(true));
        $insecure = (new ApacheRenderer())->render(Fixtures::config(), $this->options(false));

        $this->assertStringContainsString('OIDCCookie "__Host-drawbridge"', $secure);
        $this->assertStringContainsString('OIDCCookie "drawbridge"', $insecure);
        $this->assertStringContainsString('OIDCStateCookiePrefix "drawbridge_state_"', $secure);
        $this->assertStringContainsString('OIDCCookieHTTPOnly On', $secure);
    }

    public function testTrustedProxiesAreOnlyTheConfiguredOnes(): void
    {
        $with = (new ApacheRenderer())->render(Fixtures::config(), $this->options());
        $without = (new ApacheRenderer())->render(Fixtures::config(), new RenderOptions('/c.yaml', '/o'));

        $this->assertStringContainsString('RemoteIPInternalProxy 10.0.0.0/8', $with);
        $this->assertStringNotContainsString('RemoteIP', $without);
    }

    public function testHeaderModeAppsAreNotGivenTheSigningDirectives(): void
    {
        $config = Fixtures::configArray();
        $config['apps'][0]['mode'] = 'headers';
        $conf = (new ApacheRenderer())->render(\Waynestate\Drawbridge\Config\ConfigLoader::fromArray($config), $this->options());
        $echo = substr($conf, (int) strpos($conf, '# App: echo'), (int) strpos($conf, '# App: blog') - (int) strpos($conf, '# App: echo'));

        $this->assertStringNotContainsString('drawbridge-signer', $echo);
    }

    public function testNothingHostileCanBeSmuggledIntoTheApacheConfig(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RenderOptions("/etc/x.yaml\nRequire all granted", '/o');
    }

    public function testBadTrustedProxyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RenderOptions('/c.yaml', '/o', trustedProxies: ['everyone']);
    }

    public function testOptionsFromTheEnvironment(): void
    {
        $options = RenderOptions::fromEnvironment([
            'DRAWBRIDGE_PORT' => '9090',
            'DRAWBRIDGE_PUBLIC_SCHEME' => 'http',
            'DRAWBRIDGE_PUBLIC_PORT' => '8443',
            'DRAWBRIDGE_COOKIE_SECURE' => 'false',
            'DRAWBRIDGE_TRUSTED_PROXIES' => '10.0.0.0/8, 192.168.1.5',
            'DRAWBRIDGE_UNSET' => false,
        ], '/o');

        $this->assertSame(9090, $options->port);
        $this->assertSame('http', $options->scheme);
        $this->assertSame(8443, $options->publicPort);
        $this->assertFalse($options->secureCookies);
        $this->assertSame(['10.0.0.0/8', '192.168.1.5'], $options->trustedProxies);
        $this->assertSame('/etc/drawbridge/drawbridge.yaml', $options->configFile);
    }

    public function testSecureCookiesAreTheDefault(): void
    {
        $this->assertTrue(RenderOptions::fromEnvironment([], '/o')->secureCookies);
        $this->assertSame([], RenderOptions::fromEnvironment([], '/o')->trustedProxies);
    }

    public function testLuaPolicyHoldsTheHeaderRulesAndTheApps(): void
    {
        $lua = (new LuaPolicyRenderer())->render(Fixtures::config());

        foreach (['"x-sesame-assertion"', '"x_sesame_assertion"', '"x-authenticated-user"', '"x_authenticated_user"', '"x-authenticated_user"', '"x_authenticated_role"', '"x-forwarded-for"', '"remote_user"'] as $name) {
            $this->assertStringContainsString($name, $lua);
        }

        $this->assertStringContainsString('["echo.apps.example.test"] = { mode = "assertion" }', $lua);
        $this->assertStringContainsString('["blog.apps.example.test"] = { mode = "both" }', $lua);
        $this->assertStringContainsString('user_header = "X-Authenticated-User"', $lua);
    }


    public function testEveryAppHostSharesTheSignerMapAndTheMapIsStartedOnce(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());

        // Without "Inherit" a virtual host cannot see the server-level map and every lookup quietly returns nothing.
        $this->assertSame(1, substr_count($conf, 'RewriteMap drawbridge-signer'));
        $this->assertSame(count(Fixtures::config()->apps), substr_count($conf, 'RewriteOptions Inherit'));
        $this->assertStringContainsString("RewriteEngine On\nRewriteMap", $conf);
    }

    public function testSessionRequiringSectionsAskTheStampProviderToo(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());

        $this->assertStringContainsString("<RequireAll>\n            Require valid-user\n            Require drawbridge-stamp\n", $conf);
    }

    public function testTheProviderBlockNamesTheSecretsButNeverHoldsThem(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());

        $this->assertSame(1, substr_count($conf, 'OIDCProviderMetadataURL'));
        $this->assertStringContainsString('OIDCProviderMetadataURL "' . Fixtures::OIDC_ISSUER . '/.well-known/openid-configuration"', $conf);
        $this->assertStringContainsString('OIDCClientID "11111111-2222-3333-4444-555555555555"', $conf);
        $this->assertStringContainsString('OIDCClientSecret "${DRAWBRIDGE_OIDC_CLIENT_SECRET}"', $conf);
        $this->assertStringContainsString('OIDCRedirectURI "/_drawbridge/oidc/callback"', $conf);
        $this->assertStringContainsString('OIDCScope "openid profile email"', $conf);
        $this->assertStringContainsString('OIDCProviderTokenEndpointAuth client_secret_basic', $conf);
        $this->assertStringContainsString('OIDCRemoteUserClaim "preferred_username"', $conf);
        $this->assertStringContainsString('OIDCCacheEncrypt On', $conf);
        $this->assertSame(0, preg_match('/OIDC(ClientSecret|CryptoPassphrase) "(?!\$\{)/', $conf), 'a secret value was written into the config');
    }

    public function testEveryAppHostHasItsOwnCryptoPassphrase(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());

        $this->assertStringContainsString('OIDCCryptoPassphrase "${DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE}|echo.apps.example.test"', $conf);
        $this->assertStringContainsString('OIDCCryptoPassphrase "${DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE}|blog.apps.example.test"', $conf);
    }

    public function testAnAuthContextRuleAsksForItAndRequiresIt(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());
        $start = (int) strpos($conf, '<LocationMatch "^/admin/vault(/|$)">');
        $block = substr($conf, $start, (int) strpos($conf, '</LocationMatch>', $start) - $start);
        $claims = rawurlencode('{"id_token":{"acrs":{"essential":true,"value":"c1"}}}');

        $this->assertNotSame(0, $start);
        $this->assertStringContainsString('OIDCPathAuthRequestParams "claims=' . $claims . '"', $block);
        $this->assertStringContainsString('OIDCUnAutzAction auth', $block);
        $this->assertStringContainsString('Require claim acrs:c1', $block);
        $this->assertStringContainsString('Require drawbridge-stamp c1', $block);
        $this->assertStringContainsString('drawbridge-signer', $block);
    }

    public function testAnApiAuthContextRuleAnswers401(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());
        $start = (int) strpos($conf, '<LocationMatch "^/api/vault(/|$)">');
        $block = substr($conf, $start, (int) strpos($conf, '</LocationMatch>', $start) - $start);

        $this->assertNotSame(0, $start);
        $this->assertStringContainsString('OIDCUnAutzAction 401', $block);
        $this->assertStringContainsString('Require claim acrs:c2', $block);
    }

    public function testRulesWithoutAContextDoNotAskForOne(): void
    {
        $conf = (new ApacheRenderer())->render(Fixtures::config(), $this->options());
        $start = (int) strpos($conf, '<LocationMatch "^/admin(/|$)">');
        $block = substr($conf, $start, (int) strpos($conf, '</LocationMatch>', $start) - $start);

        $this->assertStringNotContainsString('OIDCPathAuthRequestParams', $block);
        $this->assertStringNotContainsString('Require claim', $block);
    }

    public function testThePolicyScrubsTheSessionAndStateCookies(): void
    {
        $lua = (new LuaPolicyRenderer())->render(Fixtures::config());

        $this->assertStringContainsString('cookies = { ["drawbridge"] = true, ["__Host-drawbridge"] = true }', $lua);
        $this->assertStringContainsString('cookie_prefixes = { "drawbridge_state_" }', $lua);
        $this->assertStringContainsString('claim_prefix = "OIDC_CLAIM_"', $lua);
        $this->assertStringContainsString('strip_prefixes = { "x-authenticated-", "x-drawbridge-", "x-sesame-", "oidc-claim-" }', $lua);
        $this->assertStringContainsString('affiliations = "affiliations"', $lua);
    }

    public function testThePolicyStripsOidcHeadersAnAttackerMightSend(): void
    {
        $lua = (new LuaPolicyRenderer())->render(Fixtures::config());

        foreach (['"oidc_claim_sub"', '"oidc-claim-preferred_username"', '"oidc_access_token"', '"oidc_id_token_payload"', '"oidc_claim_wsuroles"'] as $name) {
            $this->assertStringContainsString($name, $lua);
        }
    }

}
