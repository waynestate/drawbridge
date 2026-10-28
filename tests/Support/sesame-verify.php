<?php

declare(strict_types=1);

/**
 * Runs one DRAWBRIDGE assertion through SESAME's real GatewayDriver.
 *
 * A child process on purpose: SESAME's Composer autoloader and DRAWBRIDGE's
 * must not share a process (two autoloaders, two copies of lcobucci/jwt).
 * RealSesameTest signs the token with DRAWBRIDGE's real signer, pipes a JSON
 * request description in on stdin, and reads one JSON line out:
 *
 *   {"ok":true,"user":"jdoe@example.test"}
 *   {"ok":false,"error":"Waynestate\\Sesame\\Exception\\AuthenticationFailed: ..."}
 *
 * The driver is built the way SESAME's own GatewayDriverTest builds it: one
 * header, a pinned algorithm, the public key, the expected issuer and
 * audience, a 30-second leeway, the trusted proxy ranges, a clock and an
 * in-memory replay guard. The clock is fixed at the "now" the test passes,
 * so the cases do not depend on the wall clock.
 */

use Nyholm\Psr7\ServerRequest;
use Psr\Clock\ClockInterface;
use Waynestate\Sesame\Authenticated;
use Waynestate\Sesame\Drivers\GatewayDriver;
use Waynestate\Sesame\Drivers\InMemoryReplayGuard;

function sesame_verify_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(2);
}

$sesamePath = getenv('SESAME_PATH');

if ($sesamePath === false || $sesamePath === '') {
    sesame_verify_fail('SESAME_PATH is not set.');
}

$autoload = $sesamePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (! is_file($autoload)) {
    sesame_verify_fail("SESAME_PATH ({$sesamePath}) has no vendor/autoload.php; run composer install in the SESAME checkout first.");
}

require $autoload;

$input = stream_get_contents(STDIN);

if ($input === false || trim($input) === '') {
    sesame_verify_fail('No request description on stdin.');
}

try {
    $description = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    sesame_verify_fail('The request description on stdin is not JSON: ' . $e->getMessage());
}

if (! is_array($description)) {
    sesame_verify_fail('The request description on stdin is not a JSON object.');
}

foreach (['token', 'peer', 'public_key', 'issuer', 'audience', 'now'] as $field) {
    if (! isset($description[$field]) || ! is_string($description[$field]) || $description[$field] === '') {
        sesame_verify_fail("The request description has no usable \"{$field}\" field.");
    }
}

try {
    $now = new DateTimeImmutable($description['now']);
} catch (Exception $e) {
    sesame_verify_fail('The "now" field is not a usable time: ' . $e->getMessage());
}

$clock = new class ($now) implements ClockInterface {
    public function __construct(private readonly DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
};

$driver = new GatewayDriver(
    'X-Sesame-Assertion',
    'ES256',
    $description['public_key'],
    $description['issuer'],
    $description['audience'],
    30,
    ['10.0.0.0/8'],
    $clock,
    new InMemoryReplayGuard(30, $clock),
    300,
    false,
);

$request = new ServerRequest(
    'GET',
    'https://app.test/login',
    ['X-Sesame-Assertion' => $description['token']],
    null,
    '1.1',
    ['REMOTE_ADDR' => $description['peer']],
);

try {
    $step = $driver->login($request);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()]), PHP_EOL;
    exit(0);
}

if (! $step instanceof Authenticated) {
    echo json_encode(['ok' => false, 'error' => 'The driver returned ' . $step::class . ' instead of Authenticated.']), PHP_EOL;
    exit(0);
}

echo json_encode(['ok' => true, 'user' => $step->identity->username]), PHP_EOL;
