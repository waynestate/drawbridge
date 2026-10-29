<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Metrics;

/**
 * Matches a client IP address against an allow list.
 *
 * The list may contain exact IPv4 or IPv6 addresses and IPv4 or IPv6 CIDR
 * ranges. Every entry is validated at construction time: an entry that is not
 * a valid address or CIDR throws, a /0 prefix (which would allow every
 * address of its family) is refused, and the wildcard "*" is refused. A
 * silently skipped entry would look like working config while allowing
 * nothing, or the wrong thing.
 *
 * The matching is self-contained (no framework dependency): the gateway image
 * is minimal, and the core stays framework-free. An IPv4-mapped IPv6 address
 * (::ffff:1.2.3.4) is normalized to its IPv4 form before matching, so it
 * matches IPv4 entries.
 */
final class IpAllowList
{
    /**
     * The validated allow-list entries, exactly as configured.
     *
     * @var list<string>
     */
    private array $entries;

    /**
     * @param list<string> $entries Exact IPv4/IPv6 addresses and/or IPv4/IPv6
     *                               CIDR ranges. Every entry must be a
     *                               non-empty string; anything else throws.
     *
     * @throws \InvalidArgumentException If an entry is not a valid address or
     *                                   CIDR, is a /0 range, or is "*".
     */
    public function __construct(array $entries = [])
    {
        // The docblock promises list<string>, but a caller can still pass a
        // non-string at runtime; validate each entry before trusting it.
        $this->entries = [];
        foreach ($entries as $entry) {
            $this->entries[] = self::validateEntry($entry);
        }
    }

    /**
     * Validate one allow-list entry and return it.
     *
     * @param mixed $entry A raw entry as the caller passed it; the docblock
     *                     promise is checked here, at runtime.
     *
     * @throws \InvalidArgumentException If an entry is not a valid address or
     *                                   CIDR, is a /0 range, or is "*".
     */
    private static function validateEntry(mixed $entry): string
    {
        if (!is_string($entry) || $entry === '') {
            throw new \InvalidArgumentException(
                'Metrics allow-list entries must be non-empty strings; got '
                . get_debug_type($entry) . '.',
            );
        }

        if ($entry === '*') {
            throw new \InvalidArgumentException(
                'The "*" entry allows every client and is not allowed.',
            );
        }

        if (str_contains($entry, '/')) {
            self::validateCidr($entry);
        } elseif (filter_var($entry, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException(
                "Not a valid IPv4 or IPv6 address: \"$entry\".",
            );
        }

        return $entry;
    }

    /**
     * Whether the given IP address is allowed.
     */
    public function allows(string $ip): bool
    {
        $ip = $this->normalize($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach ($this->entries as $entry) {
            if ($this->matches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether no address is allowed. An empty list allows nothing.
     */
    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Normalize an IPv4-mapped IPv6 address (::ffff:1.2.3.4) to its IPv4 form
     * so it matches IPv4 entries. Any other address is returned unchanged.
     */
    private function normalize(string $ip): string
    {
        if (str_starts_with($ip, '::ffff:')
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
        ) {
            $candidate = substr($ip, 7);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $candidate;
            }
        }

        return $ip;
    }

    /**
     * Whether the (normalized) IP matches a single allow-list entry.
     */
    private function matches(string $ip, string $entry): bool
    {
        if (!str_contains($entry, '/')) {
            return $this->sameAddress($ip, $entry);
        }

        [$subnet, $bits] = explode('/', $entry, 2);

        return $this->inCidr($ip, $subnet, (int) $bits);
    }

    /**
     * Whether two addresses are the same (byte-for-byte after packing).
     */
    private function sameAddress(string $a, string $b): bool
    {
        $packedA = @inet_pton($a);
        $packedB = @inet_pton($b);

        return $packedA !== false && $packedB !== false && $packedA === $packedB;
    }

    /**
     * Whether the IP is within the CIDR range.
     */
    private function inCidr(string $ip, string $subnet, int $prefix): bool
    {
        $packedIp = @inet_pton($ip);
        $packedNet = @inet_pton($subnet);

        if ($packedIp === false || $packedNet === false || strlen($packedIp) !== strlen($packedNet)) {
            return false;
        }

        $fullBits = strlen($packedIp) * 8;
        if ($prefix < 1 || $prefix > $fullBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0
            && substr($packedIp, 0, $fullBytes) !== substr($packedNet, 0, $fullBytes)
        ) {
            return false;
        }

        if ($remainingBits > 0) {
            $mask = 0xFF << (8 - $remainingBits) & 0xFF;
            if ((ord($packedIp[$fullBytes]) & $mask) !== (ord($packedNet[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a CIDR entry: the subnet must be a valid address and the
     * prefix an integer from 1 to 32 (IPv4) or 1 to 128 (IPv6).
     *
     * @throws \InvalidArgumentException If the subnet is not a valid address
     *                                   or the prefix length is out of range
     *                                   for the address family. A /0 prefix
     *                                   is refused: it allows every address.
     */
    private static function validateCidr(string $cidr): void
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        if (filter_var($subnet, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException("Invalid subnet address in \"$cidr\".");
        }

        $prefixLen = filter_var($bits, FILTER_VALIDATE_INT);
        if ($prefixLen === false) {
            throw new \InvalidArgumentException("Invalid CIDR prefix length in \"$cidr\".");
        }

        // A /0 range covers every address of the family.
        if ($prefixLen === 0) {
            throw new \InvalidArgumentException(
                "A /0 prefix allows every address and is not allowed in \"$cidr\".",
            );
        }

        $maxPrefix = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
        if ($prefixLen < 1 || $prefixLen > $maxPrefix) {
            throw new \InvalidArgumentException("Invalid CIDR prefix length in \"$cidr\".");
        }
    }
}
