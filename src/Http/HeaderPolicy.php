<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Http;

use Waynestate\Drawbridge\Config\GatewayConfig;

/**
 * Which request headers the gateway owns, and so removes from every request before auth runs.
 *
 * A client can send any header it likes, and so can another container on the
 * shared network. Anything that looks like an identity header, or a header
 * the gateway sets itself, is dropped first; the gateway then adds back only
 * what it has proven. Names are compared after lower-casing and turning
 * underscores into hyphens, because PHP (and others) read "X_Authenticated_User"
 * and "X-Authenticated-User" as the same thing (D2, D3).
 *
 * The Lua enforcer inside Apache is generated from this class (see
 * LuaPolicyRenderer), so the rule has one source.
 */
final class HeaderPolicy
{
    /** Prefixes the gateway owns outright. */
    private const PREFIXES = ['x-authenticated-', 'x-drawbridge-', 'x-sesame-'];

    /** Identity headers other proxies and apps are known to trust. */
    private const IDENTITY = [
        'remote-user', 'x-remote-user', 'remote-email', 'remote-name', 'auth-user', 'x-auth-user',
        'x-user', 'x-username', 'x-user-id', 'x-userid', 'x-email', 'x-user-email',
        'x-forwarded-user', 'x-forwarded-email', 'x-forwarded-preferred-username', 'x-forwarded-groups',
        'x-auth-request-user', 'x-auth-request-email', 'x-auth-request-preferred-username', 'x-auth-request-groups',
        'x-ms-client-principal', 'x-ms-client-principal-name', 'x-ms-client-principal-id',
        'x-pomerium-jwt-assertion', 'x-pomerium-claim-email', 'x-pomerium-claim-user',
        'shib-identity-provider', 'shib-session-id', 'eppn', 'uid',
    ];

    /** Forwarding headers: removed, then set again from the gateway's own view of the connection. */
    private const FORWARDING = [
        'forwarded', 'x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host', 'x-forwarded-port',
        'x-forwarded-server', 'x-forwarded-prefix', 'x-real-ip', 'x-original-forwarded-for', 'x-original-host',
        'x-client-ip', 'true-client-ip', 'cf-connecting-ip',
        // HTTPoxy: CGI-style back ends read this as HTTP_PROXY and send their own outbound requests through it.
        'proxy',
    ];

    /** Words joined to each owned prefix to build the list of names the guard removes. */
    private const PREFIX_WORDS = [
        'user', 'userid', 'user-id', 'username', 'user-name', 'uid', 'id', 'sub', 'subject', 'login', 'principal',
        'email', 'mail', 'name', 'display-name', 'given-name', 'surname', 'groups', 'group',
        'roles', 'role', 'affiliation', 'affiliations', 'eppn', 'upn', 'amr', 'auth-time', 'mfa',
        'assertion', 'token', 'jwt', 'session', 'session-id', 'key', 'signature', 'admin', 'is-admin',
    ];

    /** The only Connection tokens that pass. Any other token could name a header and make the proxy drop it. */
    private const CONNECTION_TOKENS = ['close', 'keep-alive', 'upgrade'];

    /** @var list<string> */
    private array $names;

    /** @var list<string> */
    private array $prefixes;

    /**
     * @param  list<string>  $extra  Extra names; one ending in "-" is a prefix.
     */
    public function __construct(
        string $assertionHeader,
        string $userHeader,
        array $extra = [],
    ) {
        $names = array_merge(
            [self::normalize($assertionHeader), self::normalize($userHeader)],
            self::IDENTITY,
            self::FORWARDING,
        );
        $prefixes = self::PREFIXES;

        foreach ($extra as $name) {
            $normalized = self::normalize($name);

            if (str_ends_with($normalized, '-')) {
                $prefixes[] = $normalized;
            } else {
                $names[] = $normalized;
            }
        }

        $this->names = array_values(array_unique($names));
        $this->prefixes = array_values(array_unique($prefixes));
    }

    public static function fromConfig(GatewayConfig $gateway): self
    {
        $names = $gateway->extraStripHeaders;

        foreach ($gateway->attributeHeaders as $header) {
            $names[] = $header;
        }

        return new self($gateway->assertionHeader, $gateway->userHeader, $names);
    }

    /**
     * Lower-case, with underscores read as hyphens, as a back end would read them.
     */
    public static function normalize(string $name): string
    {
        return strtolower(str_replace('_', '-', trim($name)));
    }

    public function shouldStrip(string $name): bool
    {
        $normalized = self::normalize($name);

        if (in_array($normalized, $this->names, true)) {
            return true;
        }

        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Connection header value with only the harmless tokens left; empty means "remove the header".
     *
     * A client that writes "Connection: X-Sesame-Assertion" would otherwise make
     * the proxy drop the assertion the gateway just added, because a proxy
     * removes every header a Connection token names (D38).
     */
    public function filterConnection(string $value): string
    {
        $kept = [];

        foreach (explode(',', $value) as $token) {
            $token = strtolower(trim($token));

            if (in_array($token, self::CONNECTION_TOKENS, true) && ! in_array($token, $kept, true)) {
                $kept[] = $token;
            }
        }

        return implode(', ', $kept);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * @return list<string>
     */
    public function prefixes(): array
    {
        return $this->prefixes;
    }

    /**
     * Every concrete header name the guard removes, lower-case, as a back end could spell it.
     *
     * Apache cannot list the headers of a request, so the guard cannot say "remove everything that starts
     * with X-Authenticated-". It removes a list instead: every owned name, in every hyphen and underscore
     * spelling, plus each owned prefix joined to the usual identity words. The names the gateway itself sets
     * are always in the list, which is what keeps a forged copy from ever reaching an app. A made-up
     * header under an owned prefix that nothing reads is the one thing this cannot catch; apps must read only
     * the documented headers (D36).
     *
     * @return list<string>
     */
    public function expandedNames(): array
    {
        $base = $this->names;

        foreach ($this->prefixes as $prefix) {
            foreach (self::PREFIX_WORDS as $word) {
                $base[] = $prefix . $word;
            }
        }

        $all = [];

        foreach (array_unique($base) as $name) {
            foreach (self::spellings($name) as $spelling) {
                $all[$spelling] = true;
            }
        }

        return array_map('strval', array_keys($all));
    }

    /**
     * The name with each hyphen either kept or written as an underscore (up to six hyphens, to bound the list).
     *
     * @return list<string>
     */
    private static function spellings(string $name): array
    {
        $positions = [];
        $offset = 0;

        while (($found = strpos($name, '-', $offset)) !== false) {
            $positions[] = $found;
            $offset = $found + 1;
        }

        $positions = array_slice($positions, 0, 6);
        $spellings = [];

        for ($mask = 0; $mask < (1 << count($positions)); $mask++) {
            $variant = $name;

            foreach ($positions as $bit => $position) {
                if (($mask & (1 << $bit)) !== 0) {
                    $variant[$position] = '_';
                }
            }

            $spellings[] = $variant;
        }

        return $spellings;
    }
}
