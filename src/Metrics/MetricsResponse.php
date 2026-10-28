<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Metrics;

/**
 * The response a metrics endpoint produces for a request.
 *
 * A small value object so the framework-free handler and whatever serves it
 * (a raw PHP script, a Laravel controller, or a Lua shell-out) agree on the
 * status code, the Content-Type, and the body.
 */
final class MetricsResponse
{
    /**
     * @param int $status  The HTTP status code.
     * @param string $contentType  The Content-Type header value.
     * @param string $body  The response body (the rendered metrics, or empty).
     */
    public function __construct(
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body,
    ) {}
}
