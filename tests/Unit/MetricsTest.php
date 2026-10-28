<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Metrics\Metrics;

final class MetricsTest extends TestCase
{
    private function metrics(): Metrics
    {
        return new Metrics(null, '1.2.3');
    }

    public function testMimeType(): void
    {
        $this->assertSame('text/plain; version=0.0.4', Metrics::mimeType());
    }

    public function testRenderIsPrometheusTextFormat(): void
    {
        $output = $this->metrics()->render();

        $this->assertStringStartsWith('# HELP ', $output);
        $this->assertStringContainsString('# TYPE ', $output);
        $this->assertStringEndsWith("\n", $output);
    }

    public function testBuildInfoIsPresent(): void
    {
        $output = $this->metrics()->render();

        $this->assertStringContainsString('# TYPE drawbridge_build_info gauge', $output);
        $this->assertStringContainsString('drawbridge_build_info{version="1.2.3"} 1', $output);
    }

    public function testCountersAppearOnceIncremented(): void
    {
        $metrics = $this->metrics();

        $this->assertStringNotContainsString('drawbridge_signing_requests_total', $metrics->render());

        $metrics->recordSigningRequest();

        $this->assertStringContainsString('drawbridge_signing_requests_total 1', $metrics->render());
    }

    public function testSigningRequestCounterIncrements(): void
    {
        $metrics = $this->metrics();
        $metrics->recordSigningRequest();
        $metrics->recordSigningRequest();

        $this->assertStringContainsString('drawbridge_signing_requests_total 2', $metrics->render());
    }

    public function testSignedAssertionCounter(): void
    {
        $metrics = $this->metrics();
        $metrics->recordSignedAssertion();

        $this->assertStringContainsString('# TYPE drawbridge_assertions_signed_total counter', $metrics->render());
        $this->assertStringContainsString('drawbridge_assertions_signed_total 1', $metrics->render());
    }

    public function testAssertionErrorCounterCarriesLowCardinalityReason(): void
    {
        $metrics = $this->metrics();
        $metrics->recordAssertionError('refused');
        $metrics->recordAssertionError('failed');

        $output = $metrics->render();

        $this->assertStringContainsString('# TYPE drawbridge_assertion_errors_total counter', $output);
        $this->assertStringContainsString('drawbridge_assertion_errors_total{reason="refused"} 1', $output);
        $this->assertStringContainsString('drawbridge_assertion_errors_total{reason="failed"} 1', $output);
    }

    public function testUnknownReasonIsFoldedToOther(): void
    {
        $metrics = $this->metrics();
        $metrics->recordAssertionError('Lcobucci\JWT\Token\Invalid');

        $output = $metrics->render();

        $this->assertStringContainsString('drawbridge_assertion_errors_total{reason="other"} 1', $output);
        $this->assertStringNotContainsString('Lcobucci', $output);
    }

    public function testVersionLabelIsLowCardinality(): void
    {
        $output = $this->metrics()->render();

        // The only label on build_info is the version; no identity, claim, or
        // token ever reaches a label.
        $this->assertStringContainsString('drawbridge_build_info{version="1.2.3"} 1', $output);
        $this->assertStringNotContainsString('preferred_username', $output);
        $this->assertStringNotContainsString('jdoe', $output);
    }
}
