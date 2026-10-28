<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Config;

/**
 * One path rule: an exact path, or a prefix that ends in a slash.
 *
 * "/health" matches only "/health". "/admin/" matches "/admin" and everything
 * under "/admin/", but not "/administrator". There are no wildcards or
 * regular expressions on purpose: the renderer builds the Apache patterns
 * from these literals, so nobody hand-writes a pattern that quietly leaves
 * a hole.
 *
 * A login or api rule may also name an Entra authentication context ("c1"
 * to "c99"). The gateway asks Entra for it and lets the request through only
 * when the ID token's "acrs" claim lists it.
 */
final readonly class PathRule
{
    public function __construct(
        public string $path,
        public Protection $protection,
        public ?string $authContext = null,
    ) {}

    public function isPrefix(): bool
    {
        return str_ends_with($this->path, '/');
    }

    /** The path without its trailing slash; "/" becomes "". */
    public function base(): string
    {
        return rtrim($this->path, '/');
    }

    /**
     * Whether a normalized request path falls under this rule.
     */
    public function matches(string $normalizedPath): bool
    {
        if (! $this->isPrefix()) {
            return $normalizedPath === $this->path;
        }

        $base = $this->base();

        return $normalizedPath === $base
            || $normalizedPath === $base . '/'
            || str_starts_with($normalizedPath, $base . '/');
    }

    /**
     * Higher is more specific. A longer base wins; at an equal base an exact rule beats a prefix rule.
     */
    public function specificity(): int
    {
        return strlen($this->base()) * 2 + ($this->isPrefix() ? 0 : 1);
    }

    /**
     * The Apache LocationMatch pattern for this rule. Only validated literal characters reach here.
     */
    public function apachePattern(): string
    {
        // Config paths allow only letters, digits and . _ ~ - /, so a dot is the one character to neutralise (as [.], which needs no backslash).
        $base = str_replace('.', '[.]', $this->base());

        return $this->isPrefix() ? '^' . $base . '(/|$)' : '^' . $base . '$';
    }
}
