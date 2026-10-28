<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Metrics\BearerToken;

final class BearerTokenTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function headers(): array
    {
        return [
            'a plain bearer token' => ['Bearer s3cr3t', 's3cr3t'],
            'lowercase scheme' => ['bearer s3cr3t', 's3cr3t'],
            'mixed-case scheme' => ['BeArEr s3cr3t', 's3cr3t'],
            'extra spaces after the scheme' => ['Bearer    s3cr3t', 's3cr3t'],
            'a token with dots and dashes' => ['Bearer abc-123.def', 'abc-123.def'],
            'no header' => [null, null],
            'empty header' => ['', null],
            'a different scheme' => ['Basic dXNlcjpwYXNz', null],
            'bearer with no token' => ['Bearer', null],
            'bearer with only spaces' => ['Bearer   ', null],
            'two tokens' => ['Bearer a b', null],
            'leading garbage' => ['x Bearer s3cr3t', null],
            'trailing garbage' => ['Bearer s3cr3t x', null],
        ];
    }

    #[DataProvider('headers')]
    public function testFrom(?string $header, ?string $expected): void
    {
        $this->assertSame($expected, BearerToken::from($header));
    }
}
