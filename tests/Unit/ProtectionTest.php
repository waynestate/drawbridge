<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Config\Protection;
use Waynestate\Drawbridge\Tests\Support\Fixtures;

final class ProtectionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, Protection}>
     */
    public static function requests(): array
    {
        return [
            'unlisted path takes the safe default' => ['echo', '/anything', Protection::Login],
            'root takes the default' => ['echo', '/', Protection::Login],
            'exact public' => ['echo', '/up', Protection::Open],
            'exact rule does not cover children' => ['echo', '/up/more', Protection::Login],
            'exact rule does not cover a longer name' => ['echo', '/upload', Protection::Login],
            'prefix public' => ['echo', '/assets/app.css', Protection::Open],
            'prefix covers its own base' => ['echo', '/assets', Protection::Open],
            'prefix does not cover a look-alike' => ['echo', '/assets-private/x', Protection::Login],
            'api prefix' => ['echo', '/api/users', Protection::Api],
            'protected prefix' => ['echo', '/admin/users', Protection::Login],
            'more specific exact beats the prefix' => ['echo', '/admin/open', Protection::Open],
            'query string is never matched' => ['echo', '/admin/users?x=/up', Protection::Login],
            'query string cannot make a path public' => ['echo', '/anything?/assets/', Protection::Login],
            'dot dot cannot walk out of a public prefix' => ['echo', '/assets/../admin/users', Protection::Login],
            'encoded dot dot cannot walk out either' => ['echo', '/assets/%2e%2e/admin/users', Protection::Login],
            'refused path fails closed' => ['echo', '/assets/x;jsessionid=1', Protection::Login],
            'encoded slash fails closed' => ['echo', '/assets%2f..%2fadmin', Protection::Login],
            'a public-by-default app stays open' => ['blog', '/hello', Protection::Open],
            'a public-by-default app still protects its admin' => ['blog', '/wp-admin/options.php', Protection::Login],
            'a refused path is protected even when the app is public' => ['blog', '/hello;x', Protection::Login],
        ];
    }

    #[DataProvider('requests')]
    public function testProtectionFor(string $app, string $path, Protection $expected): void
    {
        $config = Fixtures::config();

        foreach ($config->apps as $candidate) {
            if ($candidate->id === $app) {
                $this->assertSame($expected, $candidate->protectionFor($path));

                return;
            }
        }

        $this->fail("No app {$app} in the fixture.");
    }

    public function testRulesAreSortedLeastSpecificFirst(): void
    {
        $paths = array_map(static fn($rule): string => $rule->path, Fixtures::config()->apps[0]->rules);

        $this->assertSame('/up', $paths[0] === '/up' ? '/up' : $paths[0]);
        $this->assertGreaterThan(array_search('/admin/', $paths, true), array_search('/admin/open', $paths, true));
    }

    public function testApachePatternsAreBuiltFromLiterals(): void
    {
        $rules = [];

        foreach (Fixtures::config()->apps[0]->rules as $rule) {
            $rules[$rule->path] = $rule->apachePattern();
        }

        $this->assertSame('^/admin(/|$)', $rules['/admin/']);
        $this->assertSame('^/admin/open$', $rules['/admin/open']);
        $this->assertSame('^/assets(/|$)', $rules['/assets/']);
        $this->assertSame('^/up$', $rules['/up']);
    }
}
