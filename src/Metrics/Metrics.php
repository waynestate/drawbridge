<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Metrics;

use Composer\InstalledVersions;
use OutOfBoundsException;
use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;

/**
 * Builds the Drawbridge /metrics payload.
 *
 * Framework-free: this class knows nothing about Apache, mod_auth_openidc, or
 * any web framework. It builds a CollectorRegistry, registers the Drawbridge
 * build-info gauge and (optionally) the assertion-signing counters, and
 * renders the result in the Prometheus text exposition format.
 *
 * The counters record what the assertion signer actually observes: the signing
 * requests it receives (protected paths on apps that send assertions), the
 * assertions it signs, and the signings it refuses or fails. Public requests
 * and authentication failures never reach the PHP side, so they are not
 * counted here; mod_auth_openidc would need its own status or log scraping to
 * expose those.
 *
 * The default storage adapter is InMemory, which is correct for a request
 * scope: each request builds its own registry and renders it once. The signer
 * is a long-running process, so it keeps its counters in memory for its
 * lifetime. The per-request /metrics endpoint registers no counters until a
 * shared store (for example Prometheus\Storage\APCng) lets it see the signer's
 * counters; without one it reports build info only, never a counter that is
 * always zero.
 */
final class Metrics
{
    /**
     * The Prometheus namespace for every Drawbridge metric.
     */
    private const NAMESPACE = 'drawbridge';

    /**
     * The low-cardinality reasons an assertion signing can fail. Anything else
     * is folded into "other" so a label never carries an exception message or
     * a class name.
     *
     * @var list<string>
     */
    private const ERROR_REASONS = ['refused', 'failed'];

    private CollectorRegistry $registry;

    private RenderTextFormat $renderer;

    private ?Counter $signingRequests = null;

    private ?Counter $signedAssertions = null;

    private ?Counter $assertionErrors = null;

    /**
     * @param Adapter|null $storage  The storage adapter. Defaults to InMemory.
     * @param string|null $version  The Drawbridge version for the build-info
     *        metric. Defaults to the installed package version.
     * @param bool $counters  Whether to register the assertion-signing
     *        counters. The signer passes true (its in-process counters); the
     *        per-request /metrics endpoint passes false until a shared store
     *        lets it see the signer's counters, so it never shows a counter
     *        that is always zero.
     */
    public function __construct(
        ?Adapter $storage = null,
        ?string $version = null,
        bool $counters = true,
    ) {
        // registerDefaultMetrics is false: promphp's default php_info gauge
        // would duplicate the PHP version that drawbridge_build_info already
        // carries.
        $this->registry = new CollectorRegistry($storage ?? new InMemory(), false);
        $this->renderer = new RenderTextFormat();

        $this->registerBuildInfo($version ?? $this->installedVersion());

        if ($counters) {
            $this->signingRequests = $this->registry->registerCounter(
                self::NAMESPACE,
                'signing_requests_total',
                'Signing requests the assertion signer received (protected paths on apps that send assertions).',
            );
            $this->signedAssertions = $this->registry->registerCounter(
                self::NAMESPACE,
                'assertions_signed_total',
                'Assertions the signer signed successfully.',
            );
            $this->assertionErrors = $this->registry->registerCounter(
                self::NAMESPACE,
                'assertion_errors_total',
                'Signing requests the signer refused or failed, by reason.',
                ['reason'],
            );
        }
    }

    /**
     * Count a signing request the signer received.
     */
    public function recordSigningRequest(): void
    {
        $this->signingRequests?->inc();
    }

    /**
     * Count an assertion the signer signed successfully.
     */
    public function recordSignedAssertion(): void
    {
        $this->signedAssertions?->inc();
    }

    /**
     * Count a signing the signer refused or failed.
     *
     * @param string $reason  A free-form reason; folded to a low-cardinality
     *        label value (refused, failed, or other).
     */
    public function recordAssertionError(string $reason): void
    {
        $this->assertionErrors?->inc([self::cleanReason($reason)]);
    }

    /**
     * The metrics in the Prometheus text exposition format.
     */
    public function render(): string
    {
        return $this->renderer->render($this->registry->getMetricFamilySamples());
    }

    /**
     * The Content-Type of the rendered metrics.
     */
    public static function mimeType(): string
    {
        return RenderTextFormat::MIME_TYPE;
    }

    /**
     * drawbridge_build_info: a gauge of 1, labelled with the Drawbridge
     * version. Its presence is what a scraper or operator wants to see.
     */
    private function registerBuildInfo(string $version): void
    {
        $gauge = $this->registry->registerGauge(
            self::NAMESPACE,
            'build_info',
            'DRAWBRIDGE is running.',
            ['version'],
        );
        $gauge->set(1.0, [$version]);
    }

    /**
     * Fold a free-form reason to a low-cardinality label value.
     */
    private static function cleanReason(string $reason): string
    {
        return in_array($reason, self::ERROR_REASONS, true) ? $reason : 'other';
    }

    /**
     * The installed package version, or "unknown" when it cannot be read.
     */
    private function installedVersion(): string
    {
        try {
            $version = InstalledVersions::getVersion('waynestate/drawbridge');
        } catch (OutOfBoundsException) {
            return 'unknown';
        }

        return $version ?? 'unknown';
    }
}
