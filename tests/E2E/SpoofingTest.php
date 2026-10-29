<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A client that writes the gateway's headers itself must never get them to the app. Every spelling, every path kind,
 * signed in or not. Raw sockets, so nothing tidies the header lines before they go out.
 */
final class SpoofingTest extends RigTestCase
{
    private const ECHO = 'echo.apps.test';

    /**
     * @return array<string, array{string}>
     */
    public static function spoofedHeaders(): array
    {
        return [
            'the user header' => ['X-Authenticated-User: admin'],
            'lower case' => ['x-authenticated-user: admin'],
            'shouting' => ['X-AUTHENTICATED-USER: admin'],
            'underscores' => ['X_Authenticated_User: admin'],
            'mixed underscores' => ['X-Authenticated_User: admin'],
            'underscores, lower case' => ['x_authenticated_user: admin'],
            'email' => ['X-Authenticated-Email: root@example.test'],
            'affiliations' => ['X-Authenticated-Affiliations: employee'],
            'an invented attribute under the owned prefix' => ['X-Authenticated-Role: admin'],
            'Remote-User' => ['Remote-User: admin'],
            'X-Remote-User' => ['X-Remote-User: admin'],
            'REMOTE_USER' => ['REMOTE_USER: admin'],
            'the assertion header' => ['X-Sesame-Assertion: aaa.bbb.ccc'],
            'the assertion header, underscores' => ['X_Sesame_Assertion: aaa.bbb.ccc'],
            'forwarded for' => ['X-Forwarded-For: 203.0.113.9'],
            'forwarded host' => ['X-Forwarded-Host: evil.example.test'],
            'forwarded proto' => ['X-Forwarded-Proto: https'],
            'Forwarded' => ['Forwarded: for=203.0.113.9'],
            'X-Real-IP' => ['X-Real-IP: 203.0.113.9'],
            'oauth2-proxy style' => ['X-Auth-Request-User: admin'],
            'a DRAWBRIDGE header' => ['X-Drawbridge-User: admin'],
            'a mod_auth_openidc claim' => ['OIDC_CLAIM_sub: admin'],
            'a mod_auth_openidc claim, hyphens' => ['OIDC-CLAIM-preferred_username: admin'],
            'a claim the config passes on' => ['OIDC_CLAIM_wsu_roles: admin'],
            'an invented claim' => ['OIDC_CLAIM_isAdmin: admin'],
            'the access token' => ['OIDC_access_token: admin'],
            'the ID token payload' => ['OIDC_id_token_payload: {"preferred_username":"admin"}'],
            'userinfo' => ['OIDC_userinfo_json: {"preferred_username":"admin"}'],
        ];
    }

    /**
     * @param  array<string, string>  $vars
     * @return list<string>
     */
    private function leaked(array $vars): array
    {
        $found = [];

        foreach ($vars as $name => $value) {
            if (preg_match('/^HTTP_(X_AUTHENTICATED_|X_SESAME_|X_DRAWBRIDGE_|REMOTE_USER|X_REMOTE_USER|X_AUTH_REQUEST_|X_REAL_IP|FORWARDED$|OIDC)/i', $name) === 1) {
                $found[] = $name . '=' . $value;
            }
        }

        return $found;
    }

    #[DataProvider('spoofedHeaders')]
    public function testASpoofedHeaderNeverReachesAPublicPath(string $line): void
    {
        $response = $this->raw(self::ECHO . ':' . self::PORT, '/up', [$line]);
        $this->assertSame(200, $response['status']);
        $vars = $this->serverVars($this->json($response['body']));

        $this->assertSame([], $this->leaked($vars), 'nothing the gateway owns may arrive from the client');
        $this->assertStringNotContainsString('admin', json_encode($vars, JSON_THROW_ON_ERROR), $line);
    }

    #[DataProvider('spoofedHeaders')]
    public function testASpoofedHeaderNeverReachesAProtectedPathForASignedInUser(string $line): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        $vars = $this->serverVars($this->seen($browser, self::ECHO, '/admin/x', [$line]));

        // The only gateway header the app may see is the assertion the gateway signed itself.
        $this->assertNotSame('aaa.bbb.ccc', $vars['HTTP_X_SESAME_ASSERTION'] ?? '');
        $others = array_values(array_filter($this->leaked($vars), static fn(string $pair): bool => ! str_starts_with($pair, 'HTTP_X_SESAME_ASSERTION=')));
        $this->assertSame([], $others, $line);
    }

    public function testASpoofedUserHeaderDoesNotLetAnAnonymousVisitorIn(): void
    {
        $response = $this->raw(self::ECHO . ':' . self::PORT, '/admin/x', ['X-Authenticated-User: admin', 'X-Sesame-Assertion: aaa.bbb.ccc', 'Remote-User: admin']);

        $this->assertContains($response['status'], [302, 303]);
    }

    public function testASpoofedUserHeaderDoesNotLetAnAnonymousVisitorIntoAnApi(): void
    {
        $this->assertSame(401, $this->raw(self::ECHO . ':' . self::PORT, '/api/x', ['X-Authenticated-User: admin'])['status']);
    }

    public function testHeaderModeAppsGetTheGatewaysValueNotTheClients(): void
    {
        $browser = $this->browser();
        $this->login($browser, 'legacy.apps.test');

        $response = $this->raw('legacy.apps.test:' . self::PORT, '/', [
            'Cookie: ' . $this->cookieHeader($browser, 'legacy.apps.test'),
            'X-Authenticated-User: admin',
            'X_Authenticated_User: admin',
            'x-authenticated-email: admin@example.test',
        ]);
        $vars = $this->serverVars($this->json($response['body']));

        $this->assertSame(200, $response['status']);
        $this->assertSame('alice', $vars['HTTP_X_AUTHENTICATED_USER'] ?? null);
        $this->assertSame('alice@example.test', $vars['HTTP_X_AUTHENTICATED_EMAIL'] ?? null);
    }

    public function testForwardedForIsTheGatewaysViewNotTheClients(): void
    {
        $response = $this->raw(self::ECHO . ':' . self::PORT, '/up', ['X-Forwarded-For: 203.0.113.9, 198.51.100.1']);
        $vars = $this->serverVars($this->json($response['body']));

        $this->assertStringNotContainsString('203.0.113.9', $vars['HTTP_X_FORWARDED_FOR'] ?? '');
    }

    public function testListingAGatewayHeaderInConnectionCannotMakeTheProxyDropIt(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        // Connection names headers the next hop must remove. If it were honoured, the client could remove the signed
        // assertion and the app would see a request with none.
        $vars = $this->serverVars($this->seen($browser, self::ECHO, '/admin/x', ['Connection: X-Sesame-Assertion, X-Forwarded-For']));

        $this->assertArrayHasKey('HTTP_X_SESAME_ASSERTION', $vars);
        $this->assertArrayHasKey('HTTP_X_FORWARDED_FOR', $vars);
    }

    public function testGarbageInTheAssertionHeaderOnAProtectedPathIsReplacedNotForwarded(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);

        $vars = $this->serverVars($this->seen($browser, self::ECHO, '/admin/x', ['X-Sesame-Assertion: not-a-token']));

        $this->assertNotSame('not-a-token', $vars['HTTP_X_SESAME_ASSERTION'] ?? '');
        $this->assertMatchesRegularExpression('/^[\w-]+\.[\w-]+\.[\w-]+$/', $vars['HTTP_X_SESAME_ASSERTION'] ?? '');
    }
}
