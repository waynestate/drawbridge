<?php

// Test IdP settings. The salt and admin password are read from files the rig generates at start-up,
// so nothing in this repository is a secret, and nothing here is reused anywhere real.

$salt = trim((string) @file_get_contents('/keys/idp-salt'));

$config = [
    'baseurlpath' => 'http://idp.test/simplesaml/',
    'certdir' => '/keys/',
    'loggingdir' => '/tmp/',
    'datadir' => '/tmp/',
    'tempdir' => '/tmp/simplesaml',
    'logging.level' => SimpleSAML\Logger::NOTICE,
    'logging.handler' => 'errorlog',
    'secretsalt' => $salt === '' ? bin2hex(random_bytes(16)) : $salt,
    'auth.adminpassword' => bin2hex(random_bytes(16)),
    'admin.protectindexpage' => true,
    'admin.protectmetadata' => false,
    'technicalcontact_name' => 'DRAWBRIDGE test rig',
    'technicalcontact_email' => 'nobody@example.test',
    'timezone' => 'UTC',
    'enable.saml20-idp' => true,
    'enable.shib13-idp' => false,
    'enable.adfs-idp' => false,
    'session.cookie.secure' => false,
    'session.cookie.samesite' => null,
    'module.enable' => [
        'core' => true,
        'saml' => true,
        'admin' => false,
        'exampleauth' => true,
    ],
    'store.type' => 'phpsession',
];
