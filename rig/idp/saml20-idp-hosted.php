<?php

$metadata['__DYNAMIC:1__'] = [
    'host' => '__DEFAULT__',
    'privatekey' => 'idp.key',
    'certificate' => 'idp.crt',
    'auth' => 'example-userpass',
    // Sign both the response and the assertion, as the plan's check 19 requires of an IdP.
    'saml20.sign.response' => true,
    'saml20.sign.assertion' => true,
    'signature.algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
    'nameid.encryption' => false,
    'assertion.encryption' => false,
];
