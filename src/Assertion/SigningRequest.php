<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Assertion;

use JsonException;

/**
 * Who the gateway wants an assertion for, as decoded from one RewriteMap line.
 *
 * The line is "v2", then two base64 parts separated by dots: the app host and
 * the payload of the ID token mod_auth_openidc validated for this session (a
 * JSON object). Base64 keeps newlines and other hostile bytes in a claim value
 * from breaking the one-line-per-request protocol with Apache, which would let
 * one user's request read another's answer. Everything is length-capped before
 * it is decoded, and the signer checks each claim it uses before it can reach
 * a token.
 *
 * The payload is not verified again here: mod_auth_openidc has already checked
 * the ID token's signature, issuer, audience, nonce and lifetime before it put
 * the payload in the request environment, and a client cannot set that
 * environment. The signer still refuses a payload from another issuer.
 */
final readonly class SigningRequest
{
    private const MAX_LINE = 24576;

    private const MAX_HOST = 255;

    private const MAX_PAYLOAD = 16384;

    /**
     * @param  array<string, mixed>  $claims  The ID token claims.
     */
    public function __construct(
        public string $host,
        public array $claims,
    ) {}

    public static function fromLine(string $line): self
    {
        $line = trim($line);

        if ($line === '' || strlen($line) > self::MAX_LINE) {
            throw new InvalidSigningRequest('The signing request is empty or too long.');
        }

        $parts = explode('.', $line);

        if (count($parts) !== 3 || $parts[0] !== 'v2') {
            throw new InvalidSigningRequest('The signing request is not a v2 request with two fields.');
        }

        $host = self::decode($parts[1], 0, self::MAX_HOST);
        $payload = self::decode($parts[2], 1, self::MAX_PAYLOAD);

        if ($host === '' || preg_match('/[\x00-\x20\x7f]/', $host) === 1) {
            throw new InvalidSigningRequest('The signing request has no host, or a host with control characters.');
        }

        try {
            $claims = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidSigningRequest('The ID token payload in the signing request is not JSON.');
        }

        if (! is_array($claims) || ($claims !== [] && array_is_list($claims))) {
            throw new InvalidSigningRequest('The ID token payload in the signing request is not a JSON object.');
        }

        /** @var array<string, mixed> $claims */
        return new self($host, $claims);
    }

    private static function decode(string $encoded, int $index, int $max): string
    {
        // Checked before decoding, so a huge field never gets as far as base64_decode.
        if (strlen($encoded) > (int) ceil($max / 3) * 4) {
            throw new InvalidSigningRequest("Field {$index} of the signing request is too long.");
        }

        $value = base64_decode($encoded, true);

        if ($value === false) {
            throw new InvalidSigningRequest("Field {$index} of the signing request is not base64.");
        }

        if (preg_match('//u', $value) !== 1) {
            throw new InvalidSigningRequest("Field {$index} of the signing request is not UTF-8.");
        }

        return $value;
    }

    /**
     * The same line, built the way the Apache side builds it. The tests use it.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function toLine(string $host, array $claims): string
    {
        return implode('.', [
            'v2',
            base64_encode($host),
            base64_encode($claims === [] ? '{}' : (string) json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ]);
    }
}
