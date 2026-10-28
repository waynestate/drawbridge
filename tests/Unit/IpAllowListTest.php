<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Metrics\IpAllowList;

final class IpAllowListTest extends TestCase
{
    /**
     * @return array<string, array{list<string>, string, bool}>
     */
    public static function matchCases(): array
    {
        return [
            'exact ipv4' => [['10.0.0.1'], '10.0.0.1', true],
            'exact ipv4 no match' => [['10.0.0.1'], '10.0.0.2', false],
            'ipv4 cidr in range' => [['192.168.1.0/24'], '192.168.1.100', true],
            'ipv4 cidr out of range' => [['192.168.1.0/24'], '192.168.2.100', false],
            'ipv4 cidr partial byte in range' => [['192.168.1.64/26'], '192.168.1.100', true],
            'ipv4 cidr partial byte out of range' => [['192.168.1.64/26'], '192.168.1.200', false],
            'ipv4 cidr host bits set in subnet' => [['10.1.2.3/8'], '10.9.9.9', true],
            'ipv6 exact' => [['2001:db8::1'], '2001:db8::1', true],
            'ipv6 exact no match' => [['2001:db8::1'], '2001:db8::2', false],
            'ipv6 cidr in range' => [['2001:db8::/32'], '2001:db8:1234::1', true],
            'ipv6 cidr out of range' => [['2001:db8::/32'], '2001:db9::1', false],
            'ipv4 mapped ipv6 matches ipv4 entry' => [['10.0.0.1'], '::ffff:10.0.0.1', true],
            'ipv4 mapped ipv6 no match' => [['10.0.0.1'], '::ffff:10.0.0.2', false],
            'ipv6 does not match ipv4 entry' => [['127.0.0.1'], '::1', false],
            'multiple entries, second matches' => [['10.0.0.1', '172.16.0.0/12'], '172.20.1.1', true],
            'multiple entries, none match' => [['10.0.0.1', '172.16.0.0/12'], '192.168.0.1', false],
            'empty list allows nothing' => [[], '10.0.0.1', false],
            'garbage client ip is not allowed' => [['10.0.0.1'], 'not-an-ip', false],
        ];
    }

    #[DataProvider('matchCases')]
    public function testAllows(array $entries, string $ip, bool $expected): void
    {
        $this->assertSame($expected, (new IpAllowList($entries))->allows($ip));
    }

    /**
     * @return array<string, array{list<mixed>}>
     */
    public static function invalidEntries(): array
    {
        return [
            'not an address' => [['not-an-ip']],
            'empty string' => [['']],
            'non-string' => [[123]],
            'wildcard' => [['*']],
            'ipv4 /0' => [['0.0.0.0/0']],
            'ipv6 /0' => [['::/0']],
            'prefix too large for ipv4' => [['1.2.3.4/33']],
            'prefix too large for ipv6' => [['2001:db8::1/129']],
            'non-integer prefix' => [['1.2.3.4/abc']],
            'empty prefix' => [['1.2.3.4/']],
            'invalid subnet' => [['not-an-ip/24']],
        ];
    }

    #[DataProvider('invalidEntries')]
    public function testInvalidEntryThrows(array $entries): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IpAllowList($entries);
    }

    public function testIsEmpty(): void
    {
        $this->assertTrue((new IpAllowList([]))->isEmpty());
        $this->assertFalse((new IpAllowList(['10.0.0.1']))->isEmpty());
    }
}
