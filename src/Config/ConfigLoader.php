<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the declarative gateway config (YAML or a PHP array file) and validates all of it.
 *
 * Every problem is collected and reported together, because a deploy that
 * fails one error at a time is a deploy nobody wants to run. Anything the
 * gateway does not understand is an error, never ignored: a mistyped key in
 * a protection list must not quietly become "public".
 */
final class ConfigLoader
{
    /**
     * Entra's "amr" values (RFC 8176 names) to what the assertion says. Entra sends "mfa" only when a second factor
     * was completed. "ngcmfa" is Entra's name for an MFA-strength credential such as Windows Hello. The REFEDS entry
     * is for a provider that puts its MFA profile URI in "amr"; it never matches an Entra token, so it is harmless.
     * An unknown value maps to nothing, never to a guessed "mfa".
     */
    private const DEFAULT_AMR_MAP = [
        'pwd' => ['pwd'],
        'mfa' => ['mfa'],
        'ngcmfa' => ['mfa'],
        'otp' => ['otp'],
        'rsa' => ['rsa'],
        'wia' => ['wia'],
        'https://refeds.org/profile/mfa' => ['mfa'],
    ];

    private const GATEWAY_KEYS = [
        'issuer', 'assertion_header', 'user_header', 'attribute_headers',
        'assertion_ttl_seconds', 'session_seconds', 'session_idle_seconds', 'session_cookie', 'strip_headers',
    ];

    private const OIDC_KEYS = [
        'issuer', 'metadata_url', 'client_id', 'client_secret_env', 'user_claim', 'attribute_claims', 'extra_claims', 'amr_map',
    ];

    private const APP_KEYS = ['id', 'host', 'upstream', 'audience', 'default', 'mode', 'public', 'protected', 'api'];

    private const RULE_KEYS = ['path', 'auth_context'];

    private const ATTRIBUTES = ['email', 'name', 'affiliations'];

    /** Claims the assertion sets itself; an extra claim may not reuse one of these names. */
    private const RESERVED_CLAIMS = ['iss', 'aud', 'sub', 'iat', 'nbf', 'exp', 'jti', 'auth_time', 'amr', 'acrs', 'email', 'name', 'affiliations', 'typ', 'kid', 'alg'];

    /** The shape of a claim name we are willing to put in Apache config and a Lua table. */
    private const CLAIM_NAME = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    /** @var list<string> */
    private array $errors = [];

    public static function fromFile(string $file): Config
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new ConfigException(["The config file \"{$file}\" does not exist or cannot be read."]);
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($extension === 'php') {
            $data = (static function (string $file): mixed {
                return require $file;
            })($file);
        } elseif ($extension === 'yaml' || $extension === 'yml') {
            try {
                $data = Yaml::parseFile($file);
            } catch (ParseException $e) {
                throw new ConfigException(["The config file \"{$file}\" is not valid YAML: " . $e->getMessage()]);
            }
        } else {
            throw new ConfigException(["The config file \"{$file}\" must end in .yaml, .yml or .php."]);
        }

        if (! is_array($data)) {
            throw new ConfigException(['The config must be a map with a "gateway" and an "apps" key.']);
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): Config
    {
        return (new self())->build($data);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function build(array $data): Config
    {
        $this->unknownKeys($data, ['gateway', 'oidc', 'apps'], 'config');

        $gateway = $this->gateway(is_array($data['gateway'] ?? null) ? $data['gateway'] : null);
        $oidc = $this->oidc(is_array($data['oidc'] ?? null) ? $data['oidc'] : null);

        $apps = [];
        $rawApps = $data['apps'] ?? null;

        if (! is_array($rawApps) || ! array_is_list($rawApps) || $rawApps === []) {
            $this->errors[] = '"apps" must be a non-empty list of apps.';
            $rawApps = [];
        }

        $ids = [];
        $hosts = [];

        foreach ($rawApps as $index => $rawApp) {
            $app = $this->app($index, $rawApp);

            if ($app === null) {
                continue;
            }

            if (isset($ids[$app->id])) {
                $this->errors[] = "apps[{$index}]: the id \"{$app->id}\" is used twice.";
            }

            if (isset($hosts[$app->host])) {
                $this->errors[] = "apps[{$index}]: the host \"{$app->host}\" is used twice.";
            }

            $ids[$app->id] = true;
            $hosts[$app->host] = true;
            $apps[] = $app;
        }

        if ($this->errors !== []) {
            throw new ConfigException($this->errors);
        }

        return new Config($gateway, $oidc, $apps);
    }

    /**
     * @param  array<mixed>|null  $raw
     */
    private function gateway(?array $raw): GatewayConfig
    {
        if ($raw === null) {
            $this->errors[] = '"gateway" must be a map.';
            $raw = [];
        }

        $this->unknownKeys($raw, self::GATEWAY_KEYS, 'gateway');

        $issuer = $this->url($raw['issuer'] ?? null, 'gateway.issuer');

        $assertionHeader = $this->headerName($raw['assertion_header'] ?? 'X-Sesame-Assertion', 'gateway.assertion_header');
        $userHeader = $this->headerName($raw['user_header'] ?? 'X-Authenticated-User', 'gateway.user_header');

        $attributeHeaders = ['email' => 'X-Authenticated-Email', 'name' => 'X-Authenticated-Name', 'affiliations' => 'X-Authenticated-Affiliations'];
        $rawHeaders = $raw['attribute_headers'] ?? [];

        if (is_array($rawHeaders)) {
            foreach ($rawHeaders as $attribute => $header) {
                if (! array_key_exists($attribute, $attributeHeaders)) {
                    $this->errors[] = "gateway.attribute_headers: \"{$attribute}\" is not one of email, name, affiliations.";

                    continue;
                }

                $attributeHeaders[$attribute] = $this->headerName($header, "gateway.attribute_headers.{$attribute}");
            }
        } else {
            $this->errors[] = '"gateway.attribute_headers" must be a map.';
        }

        $ttl = $this->integer($raw['assertion_ttl_seconds'] ?? 60, 'gateway.assertion_ttl_seconds', 5, 300);
        $session = $this->integer($raw['session_seconds'] ?? 28800, 'gateway.session_seconds', 300, 604800);
        $idle = $this->integer($raw['session_idle_seconds'] ?? 3600, 'gateway.session_idle_seconds', 60, 86400);

        $cookie = $raw['session_cookie'] ?? 'drawbridge';

        if (! is_string($cookie) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $cookie) !== 1) {
            $this->errors[] = '"gateway.session_cookie" must be 1 to 40 letters, digits, "_" or "-".';
            $cookie = 'drawbridge';
        }

        $strip = [];
        $rawStrip = $raw['strip_headers'] ?? [];

        if (! is_array($rawStrip) || ! array_is_list($rawStrip)) {
            $this->errors[] = '"gateway.strip_headers" must be a list of header names.';
        } else {
            foreach ($rawStrip as $name) {
                $strip[] = $this->headerName($name, 'gateway.strip_headers', true);
            }
        }

        return new GatewayConfig(
            $issuer,
            $assertionHeader,
            $userHeader,
            $attributeHeaders,
            $ttl,
            $session,
            $idle,
            $cookie,
            $strip,
        );
    }

    /**
     * @param  array<mixed>|null  $raw
     */
    private function oidc(?array $raw): OidcConfig
    {
        if ($raw === null) {
            $this->errors[] = '"oidc" must be a map (the OpenID Connect provider: issuer, client_id, client_secret_env).';
            $raw = [];
        }

        $this->unknownKeys($raw, self::OIDC_KEYS, 'oidc');

        $issuer = $this->providerUrl($raw['issuer'] ?? null, 'oidc.issuer');
        $metadataUrl = isset($raw['metadata_url'])
            ? $this->providerUrl($raw['metadata_url'], 'oidc.metadata_url')
            : ($issuer === '' ? '' : rtrim($issuer, '/') . '/.well-known/openid-configuration');

        $clientId = $raw['client_id'] ?? null;

        if (! is_string($clientId) || preg_match('#^[A-Za-z0-9._:/-]{1,200}$#', $clientId) !== 1) {
            $this->errors[] = '"oidc.client_id" is required: the application (client) ID, letters, digits and ._:/- only.';
            $clientId = '';
        }

        $secretEnv = $raw['client_secret_env'] ?? null;

        if (! is_string($secretEnv) || preg_match('/^[A-Z_][A-Z0-9_]{0,63}$/', $secretEnv) !== 1) {
            $this->errors[] = '"oidc.client_secret_env" is required: the name of the environment variable that holds the client secret (A-Z, 0-9, _), never the secret itself.';
            $secretEnv = '';
        }

        $userClaim = $this->claimName($raw['user_claim'] ?? 'preferred_username', 'oidc.user_claim') ?? 'preferred_username';

        $attributeClaims = ['email' => 'email', 'name' => 'name'];
        $rawAttributes = $raw['attribute_claims'] ?? [];

        if (is_array($rawAttributes)) {
            foreach ($rawAttributes as $key => $value) {
                if (! in_array($key, self::ATTRIBUTES, true)) {
                    $this->errors[] = "oidc.attribute_claims: \"{$key}\" is not one of email, name, affiliations.";

                    continue;
                }

                // null switches an attribute off.
                if ($value === null) {
                    unset($attributeClaims[$key]);

                    continue;
                }

                $claim = $this->claimName($value, "oidc.attribute_claims.{$key}");

                if ($claim !== null) {
                    $attributeClaims[(string) $key] = $claim;
                }
            }
        } else {
            $this->errors[] = '"oidc.attribute_claims" must be a map.';
        }

        $extraClaims = [];
        $rawExtra = $raw['extra_claims'] ?? [];

        if (! is_array($rawExtra) || ! array_is_list($rawExtra)) {
            $this->errors[] = '"oidc.extra_claims" must be a list of claim names.';
        } else {
            foreach ($rawExtra as $value) {
                $claim = $this->claimName($value, 'oidc.extra_claims');

                if ($claim === null) {
                    continue;
                }

                if (in_array(strtolower($claim), self::RESERVED_CLAIMS, true)) {
                    $this->errors[] = "oidc.extra_claims: \"{$claim}\" is a claim the assertion sets itself.";

                    continue;
                }

                $extraClaims[] = $claim;
            }
        }

        $amrMap = self::DEFAULT_AMR_MAP;
        $rawAmr = $raw['amr_map'] ?? null;

        if ($rawAmr !== null) {
            $amrMap = [];

            if (! is_array($rawAmr)) {
                $this->errors[] = '"oidc.amr_map" must be a map of a provider "amr" value to a list of amr values.';
            } else {
                foreach ($rawAmr as $from => $values) {
                    if (! is_string($from) || $from === '' || strlen($from) > 200 || ! is_array($values) || ! array_is_list($values)) {
                        $this->errors[] = '"oidc.amr_map" must map each provider value (a string) to a list of amr values.';

                        continue;
                    }

                    $clean = [];

                    foreach ($values as $value) {
                        if (! is_string($value) || preg_match('/^[a-z0-9_-]{1,20}$/', $value) !== 1) {
                            $this->errors[] = "oidc.amr_map: \"{$from}\" has an amr value that is not a short lowercase word.";

                            continue;
                        }

                        $clean[] = $value;
                    }

                    $amrMap[$from] = $clean;
                }
            }
        }

        return new OidcConfig($issuer, $metadataUrl, $clientId, $secretEnv, $userClaim, $attributeClaims, array_values(array_unique($extraClaims)), $amrMap);
    }

    private function app(int|string $index, mixed $raw): ?AppConfig
    {
        $where = "apps[{$index}]";

        if (! is_array($raw)) {
            $this->errors[] = "{$where} must be a map.";

            return null;
        }

        $before = count($this->errors);
        $this->unknownKeys($raw, self::APP_KEYS, $where);

        $id = $raw['id'] ?? null;

        if (! is_string($id) || preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $id) !== 1) {
            $this->errors[] = "{$where}.id must be lowercase letters, digits and \"-\" (1 to 41 characters).";
            $id = '';
        }

        $where = $id !== '' ? "apps[{$id}]" : $where;

        $host = $raw['host'] ?? null;

        if (! is_string($host) || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1) {
            $this->errors[] = "{$where}.host must be a lowercase DNS name without a port.";
            $host = '';
        }

        $upstream = $raw['upstream'] ?? null;

        if (! is_string($upstream) || preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?$#', $upstream) !== 1) {
            $this->errors[] = "{$where}.upstream must look like http://host:port, with no path, query or credentials.";
            $upstream = '';
        }

        $audience = $raw['audience'] ?? $host;

        if (! is_string($audience) || preg_match('#^[A-Za-z0-9._:/-]{1,200}$#', $audience) !== 1) {
            $this->errors[] = "{$where}.audience must be a short string (it defaults to the host).";
            $audience = $host;
        }

        $default = $raw['default'] ?? 'login';

        if (! is_string($default) || ! in_array($default, ['login', 'public'], true)) {
            $this->errors[] = "{$where}.default must be \"login\" or \"public\".";
            $default = 'login';
        }

        $mode = $raw['mode'] ?? AppConfig::MODE_ASSERTION;

        if (! is_string($mode) || ! in_array($mode, [AppConfig::MODE_ASSERTION, AppConfig::MODE_HEADERS, AppConfig::MODE_BOTH], true)) {
            $this->errors[] = "{$where}.mode must be \"assertion\", \"headers\" or \"both\".";
            $mode = AppConfig::MODE_ASSERTION;
        }

        $rules = [];
        $seen = [];

        foreach (['public' => Protection::Open, 'protected' => Protection::Login, 'api' => Protection::Api] as $key => $protection) {
            $list = $raw[$key] ?? [];

            if (! is_array($list) || ! array_is_list($list)) {
                $this->errors[] = "{$where}.{$key} must be a list of paths.";

                continue;
            }

            foreach ($list as $item) {
                $context = null;
                $path = $item;

                // A rule is a bare path, or a map with the path and an authentication context.
                if (is_array($item)) {
                    $this->unknownKeys($item, self::RULE_KEYS, "{$where}.{$key}");
                    $path = $item['path'] ?? null;
                    $context = $item['auth_context'] ?? null;

                    if ($protection === Protection::Open && $context !== null) {
                        $this->errors[] = "{$where}.{$key}: a public path cannot need an authentication context.";

                        continue;
                    }

                    if ($context !== null && (! is_string($context) || preg_match('/^c([1-9]|[1-9][0-9])$/', $context) !== 1)) {
                        $this->errors[] = "{$where}.{$key}: auth_context must be an Entra authentication context ID, c1 to c99.";

                        continue;
                    }
                }

                $problem = $this->pathProblem($path);

                if ($problem !== null || ! is_string($path)) {
                    $this->errors[] = "{$where}.{$key}: " . ($problem ?? 'every path must be a string.');

                    continue;
                }

                if (isset($seen[$path])) {
                    $this->errors[] = "{$where}: the path \"{$path}\" is listed under both \"{$seen[$path]}\" and \"{$key}\".";

                    continue;
                }

                $seen[$path] = $key;
                $rules[] = new PathRule($path, $protection, is_string($context) ? $context : null);
            }
        }

        usort($rules, static fn(PathRule $a, PathRule $b): int => $a->specificity() <=> $b->specificity());

        if (count($this->errors) > $before) {
            return null;
        }

        return new AppConfig(
            $id,
            $host,
            $upstream,
            $audience,
            $default === 'public' ? Protection::Open : Protection::Login,
            $mode,
            $rules,
        );
    }

    /**
     * Why a configured path is refused, or null when it is fine.
     */
    private function pathProblem(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return 'every path must be a non-empty string.';
        }

        if ($path[0] !== '/') {
            return "\"{$path}\" must start with \"/\".";
        }

        if (preg_match('#^[A-Za-z0-9._~/-]+$#', $path) !== 1) {
            return "\"{$path}\" may use only letters, digits and . _ ~ - / (no wildcards, regular expressions, query strings or encoded characters).";
        }

        if (str_contains($path, '//') || str_contains($path, '/./') || str_contains($path, '/../') || str_ends_with($path, '/..') || str_ends_with($path, '/.')) {
            return "\"{$path}\" is not a normalized path.";
        }

        if ($path === '/_drawbridge' || str_starts_with($path, '/_drawbridge/')) {
            return "\"{$path}\" is inside /_drawbridge/, which the gateway keeps for itself.";
        }

        return null;
    }

    private function url(mixed $value, string $where): string
    {
        if (! is_string($value) || preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $value) !== 1) {
            $this->errors[] = "\"{$where}\" must be an http(s) URL with no query or credentials.";

            return '';
        }

        return $value;
    }

    /**
     * The provider's URL: https, or plain http only for a host under the reserved ".test" name (the local rig), which
     * can never be a real provider on the internet.
     */
    private function providerUrl(mixed $value, string $where): string
    {
        if (! is_string($value) || preg_match('#^(https?)://([A-Za-z0-9.-]+)(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#', $value, $match) !== 1) {
            $this->errors[] = "\"{$where}\" must be an https URL with no query or credentials.";

            return '';
        }

        if ($match[1] === 'http' && ! str_ends_with(strtolower($match[2]), '.test')) {
            $this->errors[] = "\"{$where}\" must use https (plain http is accepted only for a .test host, the local rig).";

            return '';
        }

        return $value;
    }

    private function claimName(mixed $value, string $where): ?string
    {
        if (! is_string($value) || preg_match(self::CLAIM_NAME, $value) !== 1) {
            $this->errors[] = "\"{$where}\" must be a claim name (a letter, then letters, digits and _).";

            return null;
        }

        return $value;
    }

    private function headerName(mixed $value, string $where, bool $allowPrefix = false): string
    {
        $pattern = $allowPrefix ? '/^[A-Za-z][A-Za-z0-9-]{0,63}$/' : '/^[A-Za-z][A-Za-z0-9-]{0,62}[A-Za-z0-9]$/';

        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            $this->errors[] = "\"{$where}\" must be a header name (letters, digits and \"-\").";

            return 'X-Invalid';
        }

        return $value;
    }

    private function integer(mixed $value, string $where, int $min, int $max): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            $this->errors[] = "\"{$where}\" must be a whole number from {$min} to {$max}.";

            return $min;
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $allowed
     */
    private function unknownKeys(array $data, array $allowed, string $where): void
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->errors[] = "{$where}: \"{$key}\" is not a known key (known: " . implode(', ', $allowed) . ').';
            }
        }
    }
}
