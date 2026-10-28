<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Metrics\IpAllowList;
use Waynestate\Drawbridge\Metrics\MetricsDecision;
use Waynestate\Drawbridge\Metrics\MetricsGate;

final class MetricsGateTest extends TestCase
{
    private function gate(bool $enabled, ?string $token, array $allow): MetricsGate
    {
        return new MetricsGate($enabled, $token, new IpAllowList($allow));
    }

    public function testDisabledIs404(): void
    {
        $decision = $this->gate(false, 'token', ['10.0.0.1'])->decide('token', '10.0.0.1');

        $this->assertSame(404, $decision->status());
        $this->assertSame('disabled', $decision->reason());
        $this->assertFalse($decision->isAllowed());
    }

    public function testEnabledButUnconfiguredIs404(): void
    {
        $decision = $this->gate(true, null, [])->decide(null, '10.0.0.1');

        $this->assertSame(404, $decision->status());
        $this->assertSame('misconfigured', $decision->reason());
        $this->assertFalse($decision->isAllowed());
    }

    public function testEmptyTokenIsTreatedAsNoToken(): void
    {
        $decision = $this->gate(true, '', [])->decide(null, '10.0.0.1');

        $this->assertSame(404, $decision->status());
        $this->assertSame('misconfigured', $decision->reason());
    }

    public function testCorrectTokenIsAllowed(): void
    {
        $decision = $this->gate(true, 's3cr3t', [])->decide('s3cr3t', '10.0.0.1');

        $this->assertSame(200, $decision->status());
        $this->assertSame('allowed', $decision->reason());
        $this->assertTrue($decision->isAllowed());
    }

    public function testWrongTokenIsDenied(): void
    {
        $decision = $this->gate(true, 's3cr3t', [])->decide('wrong', '10.0.0.1');

        $this->assertSame(403, $decision->status());
        $this->assertSame('denied', $decision->reason());
        $this->assertFalse($decision->isAllowed());
    }

    public function testNoTokenPresentedIsDenied(): void
    {
        $decision = $this->gate(true, 's3cr3t', [])->decide(null, '10.0.0.1');

        $this->assertSame(403, $decision->status());
        $this->assertSame('denied', $decision->reason());
    }

    public function testAllowedIpIsAllowed(): void
    {
        $decision = $this->gate(true, null, ['10.0.0.0/8'])->decide(null, '10.1.2.3');

        $this->assertSame(200, $decision->status());
        $this->assertTrue($decision->isAllowed());
    }

    public function testDisallowedIpIsDenied(): void
    {
        $decision = $this->gate(true, null, ['10.0.0.0/8'])->decide(null, '192.168.0.1');

        $this->assertSame(403, $decision->status());
        $this->assertFalse($decision->isAllowed());
    }

    public function testEmptyClientIpIsDenied(): void
    {
        $decision = $this->gate(true, null, ['10.0.0.0/8'])->decide(null, '');

        $this->assertSame(403, $decision->status());
        $this->assertFalse($decision->isAllowed());
    }

    public function testTokenOrAllowedIpEitherGrantsAccess(): void
    {
        $gate = $this->gate(true, 's3cr3t', ['10.0.0.0/8']);

        $this->assertTrue($gate->decide('s3cr3t', '192.168.0.1')->isAllowed());
        $this->assertTrue($gate->decide(null, '10.1.2.3')->isAllowed());
        $this->assertFalse($gate->decide('wrong', '192.168.0.1')->isAllowed());
    }

    public function testDecisionStatuses(): void
    {
        $this->assertSame(404, MetricsDecision::disabled()->status());
        $this->assertSame(404, MetricsDecision::misconfigured()->status());
        $this->assertSame(403, MetricsDecision::denied()->status());
        $this->assertSame(200, MetricsDecision::allowed()->status());
    }
}
