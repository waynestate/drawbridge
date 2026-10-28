<?php

// The pretend app behind the gateway. It reports exactly what reached it, as JSON, and nothing else:
// the test suite reads this to prove which headers survive the gateway.

header('Content-Type: application/json');

$headers = [];

foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        // As a PHP app sees them: this is the view where "X_Authenticated_User" and "X-Authenticated-User" collide.
        $headers[$key] = $value;
    }
}

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'server_vars' => $headers,
    // The raw names, so an underscore spelling can be told from a hyphen spelling.
    'raw_header_names' => array_keys(function_exists('getallheaders') ? getallheaders() : []),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
