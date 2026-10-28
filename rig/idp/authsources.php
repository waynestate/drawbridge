<?php

// One fixed test user. The password is public on purpose: this IdP lives for one test run on a private Docker network.

$config = [
    'admin' => ['core:AdminPassword'],
    'example-userpass' => [
        'exampleauth:UserPass',
        'alice:alice-test-password' => [
            'uid' => ['alice'],
            'mail' => ['alice@example.test'],
            'displayName' => ['Alice Tester'],
            'eduPersonAffiliation' => ['student', 'member'],
        ],
    ],
];
