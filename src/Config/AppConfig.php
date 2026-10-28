<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Config;

use Waynestate\Drawbridge\Http\PathNormalizer;

/**
 * One app behind the gateway: its host, where its container listens, and its path rules.
 */
final readonly class AppConfig
{
    public const MODE_ASSERTION = 'assertion';

    public const MODE_HEADERS = 'headers';

    public const MODE_BOTH = 'both';

    /**
     * @param  list<PathRule>  $rules  Sorted least specific first, which is the order Apache needs.
     */
    public function __construct(
        public string $id,
        public string $host,
        public string $upstream,
        public string $audience,
        public Protection $default,
        public string $mode,
        public array $rules,
    ) {}

    /**
     * What the gateway does with a request path. A refused path (see PathNormalizer)
     * is treated as protected, never as public.
     */
    public function protectionFor(string $rawPath): Protection
    {
        if (PathNormalizer::normalize($rawPath) === null) {
            return Protection::Login;
        }

        return $this->ruleFor($rawPath)->protection ?? $this->default;
    }

    /**
     * The most specific rule for a request path, or null when no rule names it (or the path is refused).
     */
    public function ruleFor(string $rawPath): ?PathRule
    {
        $path = PathNormalizer::normalize($rawPath);

        if ($path === null) {
            return null;
        }

        $best = null;

        foreach ($this->rules as $rule) {
            if ($rule->matches($path) && ($best === null || $rule->specificity() >= $best->specificity())) {
                $best = $rule;
            }
        }

        return $best;
    }

    public function sendsAssertion(): bool
    {
        return $this->mode === self::MODE_ASSERTION || $this->mode === self::MODE_BOTH;
    }

    public function sendsHeaders(): bool
    {
        return $this->mode === self::MODE_HEADERS || $this->mode === self::MODE_BOTH;
    }
}
