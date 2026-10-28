<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\E2E;

use Waynestate\Drawbridge\Clock\SystemClock;
use Waynestate\Drawbridge\Tests\Support\SesameContract;

/**
 * The OpenID Connect parts: Entra authentication contexts (step-up) and the claims that must never be taken from a
 * client. In the rig, /admin/vault/ and /api/vault/ on the echo app need authentication context c1.
 */
final class OidcTest extends RigTestCase
{
    private const ECHO = 'echo.apps.test';

    private const VAULT = '/admin/vault/x';

    public function testAnAnonymousVisitorIsSentToSignInWithTheContextAskedFor(): void
    {
        $response = $this->raw(self::ECHO . ':' . self::PORT, self::VAULT);

        $this->assertContains($response['status'], [302, 303]);
        $this->assertContextAskedFor($response['headers']['location'] ?? '', 'c1');
    }

    public function testASessionWithoutTheContextIsSentBackToSignInForIt(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);
        $cookie = 'Cookie: ' . $this->cookieHeader($browser, self::ECHO);

        $this->assertSame(200, $this->raw(self::ECHO . ':' . self::PORT, '/admin/x', [$cookie])['status'], 'the session is fine where no context is needed');

        // mod_auth_openidc answers a step-up with a page that sends the browser to the provider, not with the app.
        $response = $this->raw(self::ECHO . ':' . self::PORT, self::VAULT, [$cookie]);
        $this->assertStringNotContainsString('server_vars', $response['body'], 'a token without "acrs" must not open a context path');
        $this->assertContextAskedFor($this->signInTarget($response), 'c1');
    }

    public function testAnApiContextPathAnswers401ToASessionWithoutTheContext(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        $response = $this->raw(self::ECHO . ':' . self::PORT, '/api/vault/x', ['Cookie: ' . $this->cookieHeader($browser, self::ECHO)]);

        $this->assertSame(401, $response['status']);
        $this->assertStringNotContainsString('server_vars', $response['body']);
    }

    public function testAnotherContextIsNotTheOneAskedFor(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO, '/admin/x', ['acrs' => ['c2', 'c10']]);

        $response = $this->raw(self::ECHO . ':' . self::PORT, self::VAULT, ['Cookie: ' . $this->cookieHeader($browser, self::ECHO)]);

        $this->assertStringNotContainsString('server_vars', $response['body'], '"acrs" must list c1 itself; c10 and c2 are not c1');
        $this->assertContextAskedFor($this->signInTarget($response), 'c1');
    }

    public function testStepUpThenTheContextPathOpensAndTheAssertionSaysSo(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        // The browser goes to the vault, is sent back to the provider, and this time the token carries c1.
        $browser->request('GET', 'http://' . self::ECHO . ':' . self::PORT . self::VAULT);
        $this->assertContextAskedFor($browser->getRequest()?->getUri() ?? '', 'c1');
        $this->assertCount(1, $browser->getCrawler()->filter('input[name="username"]'), 'the step-up page should have led to the sign-in form');
        $vars = $this->serverVars($this->signInAtTheProvider($browser, ['acrs' => ['c1'], 'amr' => ['pwd', 'mfa']]));

        $claims = SesameContract::accepts($vars['HTTP_X_SESAME_ASSERTION'] ?? '', $this->publicKey(), self::ISSUER, self::ECHO, new SystemClock());
        $this->assertNotNull($claims);
        $this->assertSame(['c1'], $claims['acrs'] ?? null);
        $this->assertSame(['pwd', 'mfa'], $claims['amr'] ?? null);

        $this->assertSame(200, $this->raw(self::ECHO . ':' . self::PORT, self::VAULT, ['Cookie: ' . $this->cookieHeader($browser, self::ECHO)])['status']);
        $this->assertSame(200, $this->raw(self::ECHO . ':' . self::PORT, '/api/vault/x', ['Cookie: ' . $this->cookieHeader($browser, self::ECHO)])['status']);
    }

    public function testAClientCannotClaimTheContextItself(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        $response = $this->raw(self::ECHO . ':' . self::PORT, self::VAULT, [
            'Cookie: ' . $this->cookieHeader($browser, self::ECHO),
            'OIDC_CLAIM_acrs: c1',
            'OIDC-CLAIM-acrs: c1',
            'OIDC_id_token_payload: {"acrs":["c1"]}',
        ]);

        $this->assertStringNotContainsString('server_vars', $response['body']);
        $this->assertContextAskedFor($this->signInTarget($response), 'c1');
    }

    public function testTheUserComesFromTheTokenNotFromAClaimHeader(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        $vars = $this->serverVars($this->seen($browser, self::ECHO, '/admin/x', ['OIDC_CLAIM_preferred_username: admin', 'OIDC_CLAIM_sub: admin']));
        $claims = SesameContract::accepts($vars['HTTP_X_SESAME_ASSERTION'] ?? '', $this->publicKey(), self::ISSUER, self::ECHO, new SystemClock());

        $this->assertNotNull($claims);
        $this->assertSame(self::USER, $claims['sub']);
    }

    private function assertContextAskedFor(string $location, string $context): void
    {
        $this->assertStringStartsWith(self::AUTHORIZE . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertIsString($query['claims'] ?? null, 'the sign-in request must carry a "claims" parameter');
        $this->assertSame(['id_token' => ['acrs' => ['essential' => true, 'value' => $context]]], json_decode($query['claims'], true));
    }
}
