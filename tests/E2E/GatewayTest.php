<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\E2E;

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Waynestate\Drawbridge\Clock\SystemClock;
use Waynestate\Drawbridge\Tests\Support\SesameContract;

/**
 * The gateway, end to end: login, protected and public paths, the signed assertion, and logout.
 */
final class GatewayTest extends RigTestCase
{
    private const ECHO = 'echo.apps.test';

    public function testTheHealthCheckAnswersOnTheDefaultHost(): void
    {
        $response = $this->raw('gateway:' . self::PORT, '/_drawbridge/up');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('ok', $response['body']);
    }

    public function testAnUnknownHostGetsNothing(): void
    {
        foreach (['evil.example.test', 'echo.apps.test.evil.test', 'gateway'] as $host) {
            $this->assertSame(403, $this->raw($host . ':' . self::PORT, '/')['status'], $host);
        }
    }

    public function testAPublicPathNeedsNoLoginAndCarriesNoIdentity(): void
    {
        $response = $this->raw(self::ECHO . ':' . self::PORT, '/up');
        $vars = $this->serverVars($this->json($response['body']));

        $this->assertSame(200, $response['status']);
        $this->assertArrayNotHasKey('HTTP_X_SESAME_ASSERTION', $vars);
        $this->assertArrayNotHasKey('HTTP_X_AUTHENTICATED_USER', $vars);
    }

    public function testAProtectedPathSendsAnAnonymousVisitorToSignIn(): void
    {
        $response = $this->raw(self::ECHO . ':' . self::PORT, '/');

        $this->assertContains($response['status'], [302, 303]);
        $location = $response['headers']['location'] ?? '';
        $this->assertStringStartsWith(self::AUTHORIZE . '?', $location);
        $this->assertStringContainsString('redirect_uri=' . rawurlencode('http://' . self::ECHO . ':' . self::PORT . '/_drawbridge/oidc/callback'), $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
        $this->assertStringNotContainsString('claims=', $location, 'a path with no auth context asks for none');
    }

    public function testAnApiPathAnswers401InsteadOfARedirect(): void
    {
        $response = $this->raw(self::ECHO . ':' . self::PORT, '/api/users');

        $this->assertSame(401, $response['status']);
        $this->assertArrayNotHasKey('location', $response['headers']);
    }

    public function testTheMostSpecificRuleWins(): void
    {
        $this->assertSame(200, $this->raw(self::ECHO . ':' . self::PORT, '/admin/open')['status']);
        $this->assertContains($this->raw(self::ECHO . ':' . self::PORT, '/admin/other')['status'], [302, 303]);
    }

    public function testLoginDeliversASignedAssertionThatSesameWouldAccept(): void
    {
        $browser = $this->browser();
        $echo = $this->login($browser, self::ECHO);
        $vars = $this->serverVars($echo);

        $this->assertArrayHasKey('HTTP_X_SESAME_ASSERTION', $vars, 'the app must receive the signed assertion');
        $claims = SesameContract::accepts($vars['HTTP_X_SESAME_ASSERTION'], $this->publicKey(), self::ISSUER, self::ECHO, new SystemClock());

        $this->assertNotNull($claims, 'SESAME logic must accept the assertion');
        $this->assertSame('alice', $claims['sub']);
        $this->assertSame('alice@example.test', $claims['email']);
        $this->assertSame('Alice Tester', $claims['name']);
        $this->assertEqualsCanonicalizing(['student', 'member'], $claims['affiliations']);
        $this->assertSame(['pwd'], $claims['amr'] ?? null, 'a password sign-in is amr "pwd", not MFA');
        $this->assertIsInt($claims['auth_time'] ?? null);
        $this->assertLessThanOrEqual(time() + 5, $claims['auth_time']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $claims['jti']);
        $this->assertSame(['rig-tester'], $claims['wsuRoles'] ?? null, 'a configured extra claim is copied through');
        $this->assertArrayNotHasKey('acrs', $claims, 'no authentication context was asked for or given');

        $parsed = (new Parser(new JoseEncoder()))->parse($vars['HTTP_X_SESAME_ASSERTION']);
        $this->assertInstanceOf(UnencryptedToken::class, $parsed);
        $this->assertSame('ES256', $parsed->headers()->get('alg'));
        $this->assertSame('rig-1', $parsed->headers()->get('kid'));
    }

    public function testNoOidcHeaderOrTokenEverReachesTheApp(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        foreach (['/admin/x', '/up'] as $path) {
            $vars = $this->serverVars($this->seen($browser, self::ECHO, $path));
            $leaks = array_filter(array_keys($vars), static fn(string $name): bool => stripos($name, 'OIDC') !== false);

            $this->assertSame([], array_values($leaks), $path);
            $this->assertStringNotContainsString('eyJ', implode(' ', array_diff_key($vars, ['HTTP_X_SESAME_ASSERTION' => true])), 'no provider token may reach the app on ' . $path);
        }
    }

    public function testTheAssertionIsBoundToTheAppItWasMintedFor(): void
    {
        $browser = $this->browser();
        $vars = $this->serverVars($this->login($browser, self::ECHO));
        $token = $vars['HTTP_X_SESAME_ASSERTION'];

        $this->assertNotNull(SesameContract::accepts($token, $this->publicKey(), self::ISSUER, self::ECHO, new SystemClock()));
        $this->assertNull(SesameContract::accepts($token, $this->publicKey(), self::ISSUER, 'blog.apps.test', new SystemClock()));
        $this->assertNull(SesameContract::accepts($token, $this->publicKey(), 'http://elsewhere.test', self::ECHO, new SystemClock()));
    }

    public function testEveryRequestGetsAFreshAssertionWithItsOwnJti(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        $first = $this->serverVars($this->seen($browser, self::ECHO, '/admin/x'))['HTTP_X_SESAME_ASSERTION'];
        $second = $this->serverVars($this->seen($browser, self::ECHO, '/admin/x'))['HTTP_X_SESAME_ASSERTION'];
        $parser = new Parser(new JoseEncoder());
        $one = $parser->parse($first);
        $two = $parser->parse($second);

        $this->assertInstanceOf(UnencryptedToken::class, $one);
        $this->assertInstanceOf(UnencryptedToken::class, $two);
        $this->assertNotSame($one->claims()->get('jti'), $two->claims()->get('jti'));
    }

    public function testTheGatewaySessionCookieNeverReachesTheApp(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        $cookie = $this->cookieHeader($browser, self::ECHO);
        $this->assertStringContainsString('drawbridge', $cookie, 'the browser should hold a gateway session cookie');

        $response = $this->raw(self::ECHO . ':' . self::PORT, '/admin/x', ['Cookie: app_pref=dark; ' . $cookie]);
        $this->assertSame(200, $response['status']);
        $vars = $this->serverVars($this->json($response['body']));

        $this->assertStringNotContainsString('drawbridge', $vars['HTTP_COOKIE'] ?? '');
        $this->assertStringContainsString('app_pref=dark', $vars['HTTP_COOKIE'] ?? '', "the app's own cookies must still pass");
    }

    public function testASignedInVisitorSeesPublicPathsWithoutIdentity(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        $vars = $this->serverVars($this->seen($browser, self::ECHO, '/up'));

        $this->assertArrayNotHasKey('HTTP_X_SESAME_ASSERTION', $vars);
    }

    public function testHeaderModeAppsGetPlainHeadersAndNoAssertion(): void
    {
        $browser = $this->browser();
        $vars = $this->serverVars($this->login($browser, 'legacy.apps.test'));

        $this->assertSame('alice', $vars['HTTP_X_AUTHENTICATED_USER'] ?? null);
        $this->assertSame('alice@example.test', $vars['HTTP_X_AUTHENTICATED_EMAIL'] ?? null);
        $this->assertSame('Alice Tester', $vars['HTTP_X_AUTHENTICATED_NAME'] ?? null);
        $this->assertArrayNotHasKey('HTTP_X_SESAME_ASSERTION', $vars);
    }

    public function testAPublicByDefaultAppProtectsItsCorner(): void
    {
        $this->assertSame(200, $this->raw('blog.apps.test:' . self::PORT, '/hello')['status']);
        $this->assertContains($this->raw('blog.apps.test:' . self::PORT, '/wp-admin/options.php')['status'], [302, 303]);
    }

    public function testEachAppHasItsOwnSession(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        $this->assertContains($this->raw('legacy.apps.test:' . self::PORT, '/', ['Cookie: ' . $this->cookieHeader($browser, self::ECHO)])['status'], [302, 303], 'a session on one host must not open another host');
    }

    public function testLogoutEndsTheGatewaySession(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);
        $cookie = $this->cookieHeader($browser, self::ECHO);

        $this->assertSame(200, $this->raw(self::ECHO . ':' . self::PORT, '/admin/x', ['Cookie: ' . $cookie])['status']);

        $browser->request('GET', 'http://' . self::ECHO . ':' . self::PORT . '/_drawbridge/oidc/callback?logout=' . rawurlencode('http://' . self::ECHO . ':' . self::PORT . '/up'));

        $after = $this->raw(self::ECHO . ':' . self::PORT, '/admin/x', ['Cookie: ' . $cookie]);
        $this->assertContains($after['status'], [302, 303], 'the old session cookie must be dead after logout');
    }

    public function testLogoutOnlyReturnsToTheSameHost(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);
        $browser->followRedirects(false);
        $browser->request('GET', 'http://' . self::ECHO . ':' . self::PORT . '/_drawbridge/oidc/callback?logout=' . rawurlencode('http://evil.example.test/'));

        $response = $browser->getResponse();
        $location = (string) $response->getHeader('Location');

        $this->assertStringNotContainsString('evil.example.test', $location, 'logout must never bounce a visitor to another site');
        $this->assertStringNotContainsString('evil.example.test', $response->getContent());
    }
}
