<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Shared helpers for the container rig tests: a browser that follows the OpenID Connect round trip through the mock
 * provider, and a raw socket client for the hostile requests an HTTP library would tidy up before sending.
 *
 * These tests only ever talk to the rig's own containers (gateway, mock provider, echo) on the private Docker network.
 */
abstract class RigTestCase extends TestCase
{
    protected const KEYS = '/keys';

    protected const ISSUER = 'http://auth.apps.test';

    protected const PORT = 8080;

    /** The user the tests sign in as. The mock provider signs in anyone; there is no password. */
    protected const USER = 'alice';

    /** Where the gateway sends a visitor to sign in. */
    protected const AUTHORIZE = 'http://idp.test:8080/entra/authorize';

    protected function setUp(): void
    {
        $keys = getenv('RIG_KEYS');

        if ($keys === false || ! is_file($keys . '/signing.pub')) {
            $this->markTestSkipped('The container rig is not running. Use rig/run.sh or rig/run.ps1.');
        }
    }

    protected function publicKey(): string
    {
        return (string) file_get_contents(self::KEYS . '/signing.pub');
    }

    protected function browser(): HttpBrowser
    {
        $browser = new HttpBrowser(HttpClient::create(['timeout' => 20]));
        $browser->followRedirects(true);
        $browser->setMaxRedirects(15);
        // mod_auth_openidc's step-up answer is a page with a meta refresh to the provider, as a browser would follow.
        $browser->followMetaRefresh(true);
        // What a real browser says. mod_auth_openidc answers 401 instead of redirecting a client that plainly is not one.
        $browser->setServerParameter('HTTP_ACCEPT', 'text/html,application/xhtml+xml');

        return $browser;
    }

    /**
     * The ID token claims the mock provider puts in for a sign-in, shaped like Entra's. A test can add or override any.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    protected function claims(array $claims = []): array
    {
        return array_merge([
            'preferred_username' => self::USER,
            'email' => 'alice@example.test',
            'name' => 'Alice Tester',
            'affiliations' => ['student', 'member'],
            'amr' => ['pwd'],
            'auth_time' => time(),
            'wsuRoles' => ['rig-tester'],
        ], $claims);
    }

    /**
     * Sign in through the whole OpenID Connect round trip, ending on the page the gateway returns to.
     *
     * @param  array<string, mixed>  $claims  Claims to add to (or override in) the ID token; null drops one.
     * @return array<string, mixed> The echo app's JSON for the page we landed on.
     */
    protected function login(HttpBrowser $browser, string $host, string $path = '/', array $claims = []): array
    {
        $browser->request('GET', 'http://' . $host . ':' . self::PORT . $path);

        return $this->signInAtTheProvider($browser, $claims);
    }

    /**
     * Fill in the mock provider's sign-in form the browser is looking at, and follow the redirects back.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    protected function signInAtTheProvider(HttpBrowser $browser, array $claims = []): array
    {
        $this->assertStringStartsWith(self::AUTHORIZE, $browser->getRequest()?->getUri() ?? '', 'an unauthenticated request should end at the mock provider');

        $all = array_filter($this->claims($claims), static fn(mixed $value): bool => $value !== null);
        $form = $browser->getCrawler()->filter('input[name="username"]')->ancestors()->filter('form')->form([
            'username' => (string) ($all['preferred_username'] ?? self::USER),
            'claims' => (string) json_encode($all, JSON_UNESCAPED_SLASHES),
        ]);
        $browser->submit($form);

        return $this->json($browser->getResponse()->getContent());
    }

    /**
     * Where a response sends the visitor to sign in: a redirect, or mod_auth_openidc's step-up page (a meta refresh).
     *
     * @param  array{status: int, headers: array<string, string>, body: string}  $response
     */
    protected function signInTarget(array $response): string
    {
        if (in_array($response['status'], [302, 303], true)) {
            return $response['headers']['location'] ?? '';
        }

        if (preg_match('/<meta http-equiv="refresh" content="0; url=([^"]+)">/', $response['body'], $match) === 1) {
            return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(string $body): array
    {
        $data = json_decode($body, true);
        $this->assertIsArray($data, 'expected the echo app JSON, got: ' . substr($body, 0, 300));

        return $data;
    }

    /**
     * One raw HTTP request, sent byte for byte. Header lines are given as written, so odd spellings survive.
     *
     * @param  list<string>  $headerLines  e.g. ["X_Authenticated_User: admin"]
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected function raw(string $host, string $target, array $headerLines = [], string $method = 'GET'): array
    {
        $socket = stream_socket_client('tcp://gateway:' . self::PORT, $errno, $error, 10);
        $this->assertIsResource($socket, "could not reach the gateway: {$error}");

        // Look like a browser unless the test says otherwise (see browser()).
        if (array_filter($headerLines, static fn(string $line): bool => stripos($line, 'Accept:') === 0) === []) {
            $headerLines[] = 'Accept: text/html';
        }

        $request = "{$method} {$target} HTTP/1.1\r\nHost: {$host}\r\n" . implode('', array_map(static fn(string $line): string => $line . "\r\n", $headerLines)) . "Connection: close\r\n\r\n";

        // A test may need to send its own Connection header; in that case drop ours.
        foreach ($headerLines as $line) {
            if (stripos($line, 'Connection:') === 0) {
                $request = str_replace("Connection: close\r\n\r\n", "\r\n", $request);

                break;
            }
        }

        fwrite($socket, $request);
        stream_set_timeout($socket, 15);
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        $parts = explode("\r\n\r\n", $response, 2);
        $lines = explode("\r\n", $parts[0]);
        $status = (int) (explode(' ', $lines[0])[1] ?? 0);
        $headers = [];

        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        $body = $parts[1] ?? '';

        if (($headers['transfer-encoding'] ?? '') === 'chunked') {
            $body = $this->dechunk($body);
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    private function dechunk(string $body): string
    {
        $out = '';

        while ($body !== '') {
            $lineEnd = strpos($body, "\r\n");

            if ($lineEnd === false) {
                break;
            }

            $size = (int) hexdec(trim(explode(';', substr($body, 0, $lineEnd))[0]));

            if ($size === 0) {
                break;
            }

            $out .= substr($body, $lineEnd + 2, $size);
            $body = substr($body, $lineEnd + 2 + $size + 2);
        }

        return $out;
    }

    /**
     * The gateway session cookie for a host, as a Cookie header value.
     */
    protected function cookieHeader(HttpBrowser $browser, string $host): string
    {
        $pairs = [];

        foreach ($browser->getCookieJar()->allValues('http://' . $host . ':' . self::PORT . '/') as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('; ', $pairs);
    }

    /**
     * What the echo app saw, for a request sent with an existing session.
     *
     * @param  list<string>  $extra
     * @return array<string, mixed>
     */
    protected function seen(HttpBrowser $browser, string $host, string $path, array $extra = []): array
    {
        $response = $this->raw($host . ':' . self::PORT, $path, array_merge(['Cookie: ' . $this->cookieHeader($browser, $host)], $extra));
        $this->assertSame(200, $response['status'], 'expected the app to answer 200 for ' . $path . ', got ' . $response['status'] . ': ' . substr($response['body'], 0, 200));

        return $this->json($response['body']);
    }

    /**
     * @param  array<string, mixed>  $echo
     * @return array<string, string>
     */
    protected function serverVars(array $echo): array
    {
        $vars = $echo['server_vars'] ?? [];
        $this->assertIsArray($vars);

        /** @var array<string, string> $vars */
        return $vars;
    }
}
