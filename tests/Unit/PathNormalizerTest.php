<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Http\PathNormalizer;

final class PathNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function paths(): array
    {
        return [
            'plain' => ['/admin/users', '/admin/users'],
            'root' => ['/', '/'],
            'trailing slash kept' => ['/admin/', '/admin/'],
            'query string ignored' => ['/public?next=/admin/', '/public'],
            'fragment ignored' => ['/public#x', '/public'],
            'double slash collapsed' => ['//admin//users', '/admin/users'],
            'dot segment' => ['/public/./x', '/public/x'],
            'dot dot cannot escape' => ['/public/../admin/x', '/admin/x'],
            'dot dot above root' => ['/../../admin', '/admin'],
            'encoded dot dot' => ['/public/%2e%2e/admin', '/admin'],
            'encoded letter' => ['/%61dmin', '/admin'],
            'double encoding stays literal' => ['/%2561dmin', '/%61dmin'],
            'encoded slash refused' => ['/admin%2fusers', null],
            'encoded slash upper refused' => ['/admin%2Fusers', null],
            'encoded backslash refused' => ['/admin%5cusers', null],
            'raw backslash refused' => ['/admin\\users', null],
            'path parameter refused' => ['/admin;x=1/users', null],
            'encoded semicolon refused' => ['/admin%3Bx', null],
            'nul refused' => ['/admin%00.png', null],
            'control character refused' => ['/admin%0d%0aX-Evil: 1', null],
            'bad utf-8 refused' => ['/%ff', null],
            'no leading slash' => ['admin', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('paths')]
    public function testNormalize(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, PathNormalizer::normalize($raw));
    }
}
