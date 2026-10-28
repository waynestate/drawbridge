<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Assertion;

/**
 * Who the gateway wants an assertion for, as decoded from one RewriteMap line.
 *
 * The line is "v1" followed by seven base64 parts separated by dots: host,
 * subject, email, name, affiliations (joined with "|"), the SAML
 * AuthnInstant and the AuthnContextClassRef. Base64 keeps newlines and other
 * hostile bytes in an attribute value from breaking the one-line-per-request
 * protocol with Apache, which would let one user's request read another's
 * answer. Everything is length-capped and checked for control characters
 * before it can reach a token.
 */
final readonly class SigningRequest
{
    private const MAX_LINE = 8192;

    private const MAX_FIELD = 512;

    /**
     * @param  list<string>  $affiliations
     */
    public function __construct(
        public string $host,
        public string $subject,
        public string $email,
        public string $name,
        public array $affiliations,
        public string $authInstant,
        public string $authnContext,
    ) {}

    public static function fromLine(string $line): self
    {
        $line = trim($line);

        if ($line === '' || strlen($line) > self::MAX_LINE) {
            throw new InvalidSigningRequest('The signing request is empty or too long.');
        }

        $parts = explode('.', $line);

        if (count($parts) !== 8 || $parts[0] !== 'v1') {
            throw new InvalidSigningRequest('The signing request is not a v1 request with seven fields.');
        }

        $fields = [];

        foreach (array_slice($parts, 1) as $index => $encoded) {
            $fields[$index] = self::decode($encoded, $index);
        }

        if ($fields[0] === '' || $fields[1] === '') {
            throw new InvalidSigningRequest('The signing request has no host or no subject.');
        }

        $affiliations = array_values(array_filter(
            explode('|', $fields[4]),
            static fn(string $value): bool => $value !== '',
        ));

        return new self($fields[0], $fields[1], $fields[2], $fields[3], $affiliations, $fields[5], $fields[6]);
    }

    private static function decode(string $encoded, int $index): string
    {
        $value = base64_decode($encoded, true);

        if ($value === false) {
            throw new InvalidSigningRequest("Field {$index} of the signing request is not base64.");
        }

        if (strlen($value) > self::MAX_FIELD) {
            throw new InvalidSigningRequest("Field {$index} of the signing request is too long.");
        }

        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidSigningRequest("Field {$index} of the signing request has control or non-UTF-8 characters.");
        }

        return $value;
    }

    /**
     * The same line, built the way the Apache side builds it. The tests use it.
     *
     * @param  list<string>  $affiliations
     */
    public static function toLine(string $host, string $subject, string $email = '', string $name = '', array $affiliations = [], string $authInstant = '', string $authnContext = ''): string
    {
        return implode('.', [
            'v1',
            base64_encode($host),
            base64_encode($subject),
            base64_encode($email),
            base64_encode($name),
            base64_encode(implode('|', $affiliations)),
            base64_encode($authInstant),
            base64_encode($authnContext),
        ]);
    }
}
