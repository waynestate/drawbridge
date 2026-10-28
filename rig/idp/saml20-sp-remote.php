<?php

// The one DRAWBRIDGE service provider, registered with one assertion consumer URL per rig host:
// the same shape the real IdP registration takes (OPEN_QUESTIONS.md, I-6).

$certificate = (string) @file_get_contents('/keys/sp.crt');
$certificate = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certificate);

$hosts = ['echo.apps.test', 'legacy.apps.test', 'blog.apps.test'];
$acs = [];
$slo = [];

foreach ($hosts as $index => $host) {
    $acs[] = [
        'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        'Location' => 'http://' . $host . ':8080/_drawbridge/sso/postResponse',
        'index' => $index,
    ];
    $slo[] = [
        'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        'Location' => 'http://' . $host . ':8080/_drawbridge/sso/logout',
    ];
}

$metadata['http://auth.apps.test/_drawbridge/sso/metadata'] = [
    'entityid' => 'http://auth.apps.test/_drawbridge/sso/metadata',
    'AssertionConsumerService' => $acs,
    'SingleLogoutService' => $slo,
    'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
    'certData' => $certificate,
    'simplesaml.nameidattribute' => 'uid',
    'saml20.sign.assertion' => true,
    'saml20.sign.response' => true,
    'validate.authnrequest' => false,
    'attributes' => ['uid', 'mail', 'displayName', 'eduPersonAffiliation'],
    'attributes.required' => ['uid'],
];
