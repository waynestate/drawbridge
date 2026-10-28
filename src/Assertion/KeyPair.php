<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Assertion;

use RuntimeException;

/**
 * Makes and writes an ES256 (P-256) signing key pair.
 *
 * Production keys are made on the deploy side and handed over as files; this
 * exists so the test rig and a first local run can generate their own at
 * start-up. It refuses to overwrite a file, because losing the key that
 * signs every app's logins by accident would be a very long afternoon.
 */
final class KeyPair
{
    /**
     * @return array{private: string, public: string}
     */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false || ! openssl_pkey_export($key, $private)) {
            throw new RuntimeException('OpenSSL could not generate a P-256 key: ' . (string) openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['key']) || ! is_string($details['key']) || ! is_string($private)) {
            throw new RuntimeException('OpenSSL could not export the public key.');
        }

        return ['private' => $private, 'public' => $details['key']];
    }

    public static function write(string $privateFile, string $publicFile): void
    {
        foreach ([$privateFile, $publicFile] as $file) {
            if (file_exists($file)) {
                throw new RuntimeException("Refusing to overwrite \"{$file}\".");
            }
        }

        $pair = self::generate();

        if (file_put_contents($privateFile, $pair['private']) === false || ! chmod($privateFile, 0600)) {
            throw new RuntimeException("Could not write \"{$privateFile}\".");
        }

        if (file_put_contents($publicFile, $pair['public']) === false) {
            throw new RuntimeException("Could not write \"{$publicFile}\".");
        }
    }
}
