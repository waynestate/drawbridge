<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Metrics;

/**
 * The outcome of a metrics authorization decision.
 *
 * A small value object so the gate and the endpoint speak the same language
 * about what to do with the request: which status code to send and why.
 */
final class MetricsDecision
{
    private function __construct(
        private readonly int $status,
        private readonly string $reason,
    ) {}

    /**
     * The endpoint is off. Answer 404 so the route is not advertised.
     */
    public static function disabled(): self
    {
        return new self(404, 'disabled');
    }

    /**
     * The endpoint is on but has neither a token nor an allow list. Answer
     * 404 (not 403) so the misconfiguration does not advertise the route, and
     * let the caller log a warning.
     */
    public static function misconfigured(): self
    {
        return new self(404, 'misconfigured');
    }

    /**
     * The endpoint is on and configured, but the request is not authorized.
     */
    public static function denied(): self
    {
        return new self(403, 'denied');
    }

    /**
     * The request is authorized; serve the metrics.
     */
    public static function allowed(): self
    {
        return new self(200, 'allowed');
    }

    /**
     * The HTTP status code to send.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * A short machine-readable reason: disabled, misconfigured, denied, or
     * allowed.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Whether the metrics should be served.
     */
    public function isAllowed(): bool
    {
        return $this->status === 200;
    }
}
