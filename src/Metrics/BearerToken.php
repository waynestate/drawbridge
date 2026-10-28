<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Metrics;

/**
 * Extracts a bearer token from an Authorization header value.
 *
 * The metrics endpoint accepts "Authorization: Bearer <token>". Anything that
 * is not a well-formed bearer header yields null, which the gate treats as
 * "no token presented" (and therefore not authorized by token).
 */
final class BearerToken
{
    /**
     * The bearer token, or null when the header is absent or not a bearer
     * header.
     */
    public static function from(?string $authorizationHeader): ?string
    {
        if ($authorizationHeader === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $authorizationHeader, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
