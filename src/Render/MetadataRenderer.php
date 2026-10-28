<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Render;

use DOMDocument;
use RuntimeException;
use Waynestate\Drawbridge\Config\Config;

/**
 * The one SP metadata document to hand the IdP owner.
 *
 * Every app is its own virtual host with its own session cookie (D28), so each
 * has its own assertion consumer URL, but they are all one service provider
 * with one entity ID and one certificate. Mellon makes a metadata file per
 * host; this joins them so the IdP is registered once (I-6).
 */
final class MetadataRenderer
{
    private const NS_MD = 'urn:oasis:names:tc:SAML:2.0:metadata';

    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    public function render(Config $config, string $certificatePem, string $scheme = 'https', ?int $publicPort = null): string
    {
        $certificate = $this->certificateBody($certificatePem);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $descriptor = $document->createElementNS(self::NS_MD, 'md:EntityDescriptor');
        $descriptor->setAttribute('entityID', $config->gateway->spEntityId);
        $document->appendChild($descriptor);

        $sp = $document->createElementNS(self::NS_MD, 'md:SPSSODescriptor');
        $sp->setAttribute('AuthnRequestsSigned', 'true');
        $sp->setAttribute('WantAssertionsSigned', 'true');
        $sp->setAttribute('protocolSupportEnumeration', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $descriptor->appendChild($sp);

        foreach (['signing', 'encryption'] as $use) {
            $keyDescriptor = $document->createElementNS(self::NS_MD, 'md:KeyDescriptor');
            $keyDescriptor->setAttribute('use', $use);
            $keyInfo = $document->createElementNS(self::NS_DS, 'ds:KeyInfo');
            $data = $document->createElementNS(self::NS_DS, 'ds:X509Data');
            $data->appendChild($document->createElementNS(self::NS_DS, 'ds:X509Certificate', $certificate));
            $keyInfo->appendChild($data);
            $keyDescriptor->appendChild($keyInfo);
            $sp->appendChild($keyDescriptor);
        }

        $base = '';
        $acsIndex = 0;

        foreach ($config->apps as $app) {
            $base = $scheme . '://' . $app->host . ($publicPort !== null ? ':' . $publicPort : '') . ApacheRenderer::ENDPOINT_PATH;

            $logout = $document->createElementNS(self::NS_MD, 'md:SingleLogoutService');
            $logout->setAttribute('Binding', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect');
            $logout->setAttribute('Location', $base . '/logout');
            $sp->appendChild($logout);
        }

        foreach ($config->apps as $app) {
            $base = $scheme . '://' . $app->host . ($publicPort !== null ? ':' . $publicPort : '') . ApacheRenderer::ENDPOINT_PATH;

            $acs = $document->createElementNS(self::NS_MD, 'md:AssertionConsumerService');
            $acs->setAttribute('Binding', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST');
            $acs->setAttribute('Location', $base . '/postResponse');
            $acs->setAttribute('index', (string) $acsIndex);

            if ($acsIndex === 0) {
                $acs->setAttribute('isDefault', 'true');
            }

            $sp->appendChild($acs);
            $acsIndex++;
        }

        $xml = $document->saveXML();

        if ($xml === false) {
            throw new RuntimeException('The metadata document could not be written.');
        }

        return $xml;
    }

    private function certificateBody(string $pem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $match) !== 1) {
            throw new RuntimeException('The certificate file is not a PEM certificate.');
        }

        return preg_replace('/\s+/', '', $match[1]) ?? '';
    }
}
