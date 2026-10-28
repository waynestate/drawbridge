<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ways to reach a protected path by looking like a public one. Public rules here: /up (exact), /assets/ (prefix),
 * /admin/open (exact, inside the protected /admin/ prefix). None of these may open /admin/ without a session.
 */
final class PathAttackTest extends RigTestCase
{
    private const ECHO = 'echo.apps.test';

    /**
     * @return array<string, array{string}>
     */
    public static function targets(): array
    {
        return [
            'dot-dot out of a public prefix' => ['/assets/../admin/secret'],
            'encoded dot-dot' => ['/assets/%2e%2e/admin/secret'],
            'dot then dot-dot' => ['/assets/./../admin/secret'],
            'a public exact path then dot-dot' => ['/admin/open/../secret'],
            'a public exact path with a trailing slash' => ['/admin/open/'],
            'a public exact path as a prefix' => ['/admin/openx'],
            'the public name in another case' => ['/ADMIN/open'],
            'doubled slash at the start' => ['//admin/secret'],
            'doubled slash inside' => ['/admin//secret'],
            'a path parameter' => ['/admin;/open'],
            'a path parameter on the public name' => ['/up;/../admin/secret'],
            'encoded slash' => ['/admin%2fsecret'],
            'encoded backslash' => ['/assets/..%5cadmin/secret'],
            'a NUL byte' => ['/admin/open%00/../secret'],
            'a query that looks like a public path' => ['/admin/secret?/up'],
            'a query that names a public prefix' => ['/admin/secret?x=/assets/'],
        ];
    }

    #[DataProvider('targets')]
    public function testAnAnonymousVisitorNeverReachesTheApp(string $target): void
    {
        $response = $this->raw(self::ECHO.':'.self::PORT, $target);

        // A redirect to sign in, a refusal, or a not-found are all fine. The app answering with its page is not.
        $this->assertStringNotContainsString('server_vars', $response['body'], $target.' reached the app');
    }

    public function testTheExactPublicRuleStillWorks(): void
    {
        $this->assertSame(200, $this->raw(self::ECHO.':'.self::PORT, '/admin/open')['status']);
        $this->assertSame(200, $this->raw(self::ECHO.':'.self::PORT, '/assets/site.css')['status']);
        $this->assertSame(200, $this->raw(self::ECHO.':'.self::PORT, '/up?next=/admin/')['status']);
    }

    public function testAQueryStringDoesNotChangeWhichRuleApplies(): void
    {
        $this->assertContains($this->raw(self::ECHO.':'.self::PORT, '/admin/other?/admin/open')['status'], [302, 303]);
        $this->assertContains($this->raw(self::ECHO.':'.self::PORT, '/?/up')['status'], [302, 303]);
    }

    public function testTheGatewaysOwnPathsAreNotProxiedToTheApp(): void
    {
        $response = $this->raw(self::ECHO.':'.self::PORT, '/_drawbridge/nothing');

        $this->assertStringNotContainsString('server_vars', $response['body']);
    }

    public function testTheSpMetadataIsPublicAndIsOnlyThePublicHalf(): void
    {
        $response = $this->raw(self::ECHO.':'.self::PORT, '/_drawbridge/sso/metadata');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('EntityDescriptor', $response['body']);
        $this->assertStringNotContainsString('PRIVATE KEY', $response['body']);
    }
}
