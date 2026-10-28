<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Http;

/**
 * Turns a raw request path into the one form the path rules match against.
 *
 * Drawbridge matches the decoded path and never the query string (D17, D46).
 * Anything that could make the gateway and the app disagree about which
 * resource was asked for is refused here instead of being guessed at: an
 * encoded slash, a backslash, a NUL or other control byte, and a path
 * parameter (";"). A refused path is treated as "not safe to route", and the
 * caller fails closed.
 */
final class PathNormalizer
{
    /**
     * @return string|null The normalized path, or null when the path is refused.
     */
    public static function normalize(string $rawPath): ?string
    {
        // The query string and fragment are never part of the match.
        $path = $rawPath;

        foreach (['?', '#'] as $delimiter) {
            $position = strpos($path, $delimiter);

            if ($position !== false) {
                $path = substr($path, 0, $position);
            }
        }

        if ($path === '' || $path[0] !== '/') {
            return null;
        }

        // An encoded slash or backslash would be decoded by the app (or not) differently from the gateway.
        if (preg_match('/%(?:2f|5c)/i', $path) === 1) {
            return null;
        }

        // Decode once only: a second layer ("%252e") stays literal and so matches nothing.
        $decoded = rawurldecode($path);

        if (preg_match('/[\x00-\x1f\x7f\\\\;]/', $decoded) === 1) {
            return null;
        }

        if (preg_match('//u', $decoded) !== 1) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalized = '/' . implode('/', $segments);

        // A trailing slash is kept: "/admin/" and "/admin" are different resources to many apps.
        if ($segments !== [] && str_ends_with($decoded, '/')) {
            $normalized .= '/';
        }

        return $normalized;
    }
}
