<?php

declare(strict_types=1);

// Test-rig set-up, run inside the gateway image (which has PHP and the vendor directory).
//   php prepare.php keys      make every key and certificate the rig needs (throwaway, never committed)
//   php prepare.php metadata  fetch the test IdP's metadata once it is up
// Nothing here talks to anything but the rig's own containers.

require '/opt/drawbridge/vendor/autoload.php';

use Waynestate\Drawbridge\Assertion\KeyPair;

$dir = '/keys';
$phase = $argv[1] ?? '';

function selfSigned(string $commonName, string $keyFile, string $certFile): void
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $csr = $key === false ? false : openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
    $cert = ($key === false || $csr === false) ? false : openssl_csr_sign($csr, null, $key, 2);

    if ($key === false || $cert === false || ! openssl_pkey_export($key, $private) || ! openssl_x509_export($cert, $pem)) {
        fwrite(STDERR, "could not make a certificate for {$commonName}\n");
        exit(1);
    }

    file_put_contents($keyFile, $private);
    file_put_contents($certFile, $pem);
    chmod($keyFile, 0644);
    chmod($certFile, 0644);
}

if ($phase === 'keys') {
    if (is_file("{$dir}/signing.key")) {
        echo "keys already exist in {$dir}
";
        exit(0);
    }

    selfSigned('drawbridge-sp-test', "{$dir}/sp.key", "{$dir}/sp.crt");
    selfSigned('idp-test', "{$dir}/idp.key", "{$dir}/idp.crt");
    file_put_contents("{$dir}/idp-salt", bin2hex(random_bytes(16)));

    KeyPair::write("{$dir}/signing.key", "{$dir}/signing.pub");
    chmod("{$dir}/signing.key", 0644);
    chmod("{$dir}/idp-salt", 0644);

    echo "keys written to {$dir}\n";
    exit(0);
}

if ($phase === 'metadata') {
    $url = 'http://idp.test/simplesaml/saml2/idp/metadata.php';

    for ($attempt = 0; $attempt < 60; $attempt++) {
        $xml = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 3]]));

        if (is_string($xml) && str_contains($xml, 'EntityDescriptor')) {
            file_put_contents("{$dir}/idp-metadata.xml", $xml);
            chmod("{$dir}/idp-metadata.xml", 0644);
            echo "idp metadata written\n";
            exit(0);
        }

        sleep(1);
    }

    fwrite(STDERR, "the test IdP never answered at {$url}\n");
    exit(1);
}

fwrite(STDERR, "usage: prepare.php keys|metadata\n");
exit(2);
