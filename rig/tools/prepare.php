<?php

declare(strict_types=1);

// Test-rig set-up, run inside the gateway image (which has PHP and the vendor directory).
//   php prepare.php keys  make the assertion signing key the rig needs (throwaway, never committed)
//   php prepare.php idp   wait until the mock OpenID Connect provider answers its discovery document
// Nothing here talks to anything but the rig's own containers.

require '/opt/drawbridge/vendor/autoload.php';

use Waynestate\Drawbridge\Assertion\KeyPair;

$dir = '/keys';
$phase = $argv[1] ?? '';

if ($phase === 'keys') {
    if (is_file("{$dir}/signing.key")) {
        echo "keys already exist in {$dir}\n";
        exit(0);
    }

    KeyPair::write("{$dir}/signing.key", "{$dir}/signing.pub");
    chmod("{$dir}/signing.key", 0644);

    echo "keys written to {$dir}\n";
    exit(0);
}

if ($phase === 'idp') {
    $url = 'http://idp.test:8080/entra/.well-known/openid-configuration';

    for ($attempt = 0; $attempt < 90; $attempt++) {
        $json = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 3]]));
        $document = is_string($json) ? json_decode($json, true) : null;

        if (is_array($document) && ($document['issuer'] ?? null) === 'http://idp.test:8080/entra') {
            echo "the mock provider is up\n";
            exit(0);
        }

        sleep(1);
    }

    fwrite(STDERR, "the mock provider never answered at {$url}\n");
    exit(1);
}

fwrite(STDERR, "usage: prepare.php keys|idp\n");
exit(2);
