<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Metrics;

/**
 * Handles a request to the metrics endpoint.
 *
 * Framework-free: it takes the request's bearer token and real client IP,
 * asks the gate for a decision, and builds the response. It knows nothing
 * about Apache, mod_auth_openidc, or any web framework, so it can be driven
 * from a raw PHP script, a Laravel controller, or a Lua shell-out.
 *
 * The response is:
 *  - 404 (empty body) when the endpoint is disabled or misconfigured,
 *  - 403 (empty body) when the request is not authorized,
 *  - 200 with the rendered metrics when the request is authorized.
 */
final class MetricsEndpoint
{
    /**
     * @param MetricsGate $gate  The authorization gate.
     * @param Metrics $metrics  The metrics to render when allowed.
     */
    public function __construct(
        private readonly MetricsGate $gate,
        private readonly Metrics $metrics,
    ) {}

    /**
     * Handle a request.
     *
     * @param string|null $requestToken  The bearer token the request
     *        presented, or null.
     * @param string $clientIp  The real client IP, or an empty string.
     */
    public function handle(?string $requestToken, string $clientIp): MetricsResponse
    {
        $decision = $this->gate->decide($requestToken, $clientIp);

        if (!$decision->isAllowed()) {
            return new MetricsResponse($decision->status(), 'text/plain; charset=utf-8', '');
        }

        return new MetricsResponse(200, Metrics::mimeType(), $this->metrics->render());
    }
}
