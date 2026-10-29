<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The gateway's own error pages. Apache's default English pages read like a server having a bad day;
 * these four small pages say what happened and what to do, in plain words, with no script, no external
 * asset and nothing that names a host. When the bridge is down, at least the sign is legible.
 *
 * 401 deliberately has no page: an api caller keeps its bare 401, and a browser reaches sign-in through
 * the module's own redirect.
 */
final class ErrorPagesTest extends TestCase
{
    private const PAGES_DIR = __DIR__ . '/../../docker/gateway/errors';

    private const HTTPD_CONF = __DIR__ . '/../../docker/gateway/httpd.conf';

    private const DOCKERFILE = __DIR__ . '/../../docker/gateway/Dockerfile';

    /**
     * @return array<string, array{int, string}>
     */
    public static function pageProvider(): array
    {
        return [
            'forbidden' => [403, 'You do not have access'],
            'bad gateway' => [502, 'The service is not answering'],
            'unavailable' => [503, 'The service is not answering'],
            'timeout' => [504, 'The service is not answering'],
        ];
    }

    private function page(int $code): string
    {
        $path = self::PAGES_DIR . '/' . $code . '.html';
        $this->assertFileExists($path, "the gateway must ship $code.html");
        $html = (string) file_get_contents($path);
        $this->assertNotEmpty($html, "$code.html must not be empty");

        return $html;
    }

    #[DataProvider('pageProvider')]
    public function testEachPageIsPlainSelfContainedHtml(int $code, string $reason): void
    {
        $html = $this->page($code);

        $this->assertStringStartsWith('<!doctype html>', strtolower($html));
        $this->assertStringContainsString('lang="en"', $html, "$code must declare its language");
        $this->assertStringContainsString('<meta name="viewport"', $html, "$code must carry a viewport meta");
        $this->assertSame(1, substr_count($html, '<main>'), "$code must have exactly one <main>");
        $this->assertSame(1, substr_count($html, '<h1>'), "$code must have exactly one <h1>");
        $this->assertMatchesRegularExpression(
            '#<title>\s*' . $code . ':\s*' . preg_quote($reason, '#') . '\s*</title>#',
            $html,
            "$code must name the status and a short reason in its title",
        );
        $this->assertLessThan(1536, strlen($html), "$code must stay under 1.5 KB");

        foreach (['<script', 'http://', 'https://', 'src=', 'url('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "$code must not contain $forbidden");
        }
    }

    public function testTheConfPointsEveryCoveredCodeAtAPageAndLeaves401Bare(): void
    {
        $conf = (string) file_get_contents(self::HTTPD_CONF);

        foreach ([403, 502, 503, 504] as $code) {
            $this->assertMatchesRegularExpression(
                '/^ErrorDocument\s+' . $code . '\s+\/_drawbridge\/errors\/' . $code . '\.html$/m',
                $conf,
                "httpd.conf must point $code at the error page",
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/ErrorDocument\s+401\b/',
            $conf,
            '401 must stay bare: an api caller must never get an HTML page',
        );
    }

    public function testTheImageCopiesThePagesWhereTheConfExpectsThem(): void
    {
        $dockerfile = (string) file_get_contents(self::DOCKERFILE);

        $this->assertStringContainsString(
            'COPY docker/gateway/errors',
            $dockerfile,
            'the image must copy the pages folder',
        );
        $this->assertStringContainsString(
            '/etc/drawbridge/errors',
            $dockerfile,
            'the image must put the pages where the conf expects them',
        );
    }
}
