<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Metrics\IpAllowList;
use Waynestate\Drawbridge\Metrics\Metrics;
use Waynestate\Drawbridge\Metrics\MetricsEndpoint;
use Waynestate\Drawbridge\Metrics\MetricsGate;
use Waynestate\Drawbridge\Metrics\MetricsResponse;

final class MetricsEndpointTest extends TestCase
{
    private function endpoint(bool $enabled, ?string $token, array $allow): MetricsEndpoint
    {
        $gate = new MetricsGate($enabled, $token, new IpAllowList($allow));

        return new MetricsEndpoint($gate, new Metrics(null, '1.2.3'));
    }

    private function assertStatus(MetricsResponse $response, int $status): void
    {
        $this->assertSame($status, $response->status);
    }

    public function testDisabledIs404WithEmptyBody(): void
    {
        $response = $this->endpoint(false, 'token', ['10.0.0.1'])->handle('token', '10.0.0.1');

        $this->assertStatus($response, 404);
        $this->assertSame('', $response->body);
        $this->assertStringStartsWith('text/plain', $response->contentType);
    }

    public function testMisconfiguredIs404WithEmptyBody(): void
    {
        $response = $this->endpoint(true, null, [])->handle(null, '10.0.0.1');

        $this->assertStatus($response, 404);
        $this->assertSame('', $response->body);
    }

    public function testDeniedIs403WithEmptyBody(): void
    {
        $response = $this->endpoint(true, 's3cr3t', [])->handle('wrong', '10.0.0.1');

        $this->assertStatus($response, 403);
        $this->assertSame('', $response->body);
    }

    public function testAllowedByTokenServesMetrics(): void
    {
        $response = $this->endpoint(true, 's3cr3t', [])->handle('s3cr3t', '10.0.0.1');

        $this->assertStatus($response, 200);
        $this->assertSame(Metrics::mimeType(), $response->contentType);
        $this->assertStringContainsString('drawbridge_build_info{version="1.2.3"} 1', $response->body);
    }

    public function testAllowedByIpServesMetrics(): void
    {
        $response = $this->endpoint(true, null, ['10.0.0.0/8'])->handle(null, '10.1.2.3');

        $this->assertStatus($response, 200);
        $this->assertStringContainsString('drawbridge_build_info', $response->body);
    }

    public function testServedBodyIsPrometheusTextFormat(): void
    {
        $metrics = new Metrics(null, '1.2.3');
        $metrics->recordSigningRequest();
        $metrics->recordSignedAssertion();
        $metrics->recordAssertionError('refused');

        $gate = new MetricsGate(true, 's3cr3t', new IpAllowList([]));
        $response = (new MetricsEndpoint($gate, $metrics))->handle('s3cr3t', '10.0.0.1');

        $this->assertStatus($response, 200);
        $this->assertStringStartsWith('# HELP ', $response->body);
        $this->assertStringContainsString('# TYPE drawbridge_signing_requests_total counter', $response->body);
        $this->assertStringContainsString('drawbridge_signing_requests_total 1', $response->body);
        $this->assertStringContainsString('drawbridge_assertions_signed_total 1', $response->body);
        $this->assertStringContainsString('drawbridge_assertion_errors_total{reason="refused"} 1', $response->body);
        $this->assertStringEndsWith("\n", $response->body);
    }
}
