<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\E2E;

/**
 * What the gateway does with a SAML response it should not trust. The IdP here is the rig's own; nothing leaves it.
 */
final class SamlTest extends RigTestCase
{
    private const ECHO = 'echo.apps.test';

    public function testATamperedResponseDoesNotSignAnyoneIn(): void
    {
        $browser = $this->browser();
        $browser->request('GET', 'http://'.self::ECHO.':'.self::PORT.'/');

        $form = $browser->getCrawler()->filter('input[name="username"]')->ancestors()->filter('form')->form([
            'username' => self::USER,
            'password' => self::PASSWORD,
        ]);
        $browser->submit($form);

        $crawler = $browser->getCrawler();
        $field = $crawler->filter('input[name="SAMLResponse"]');
        $this->assertGreaterThan(0, $field->count(), 'the IdP should hand back a SAML response');

        $xml = base64_decode((string) $field->attr('value'), true);
        $this->assertIsString($xml);
        // Change who the response says signed in and leave the signature alone.
        $tampered = str_replace('>alice<', '>mallory<', $xml);
        $this->assertNotSame($xml, $tampered, 'the test needs the uid in the response in the clear');

        $action = (string) $field->ancestors()->filter('form')->attr('action');
        $relay = $crawler->filter('input[name="RelayState"]');
        $browser->followRedirects(false);
        $browser->request('POST', $action, [
            'SAMLResponse' => base64_encode($tampered),
            'RelayState' => $relay->count() > 0 ? (string) $relay->attr('value') : '',
        ]);

        $this->assertNotSame(200, $browser->getResponse()->getStatusCode(), 'a tampered response must not produce a page');

        $browser->followRedirects(true);
        $browser->request('GET', 'http://'.self::ECHO.':'.self::PORT.'/admin/x');
        $this->assertStringContainsString('idp.test', $browser->getRequest()?->getUri() ?? '', 'still no session after the tampered response');
    }

    public function testAPlainGarbageResponseIsRefused(): void
    {
        $browser = $this->browser();
        $browser->followRedirects(false);
        $browser->request('POST', 'http://'.self::ECHO.':'.self::PORT.'/_drawbridge/sso/postResponse', [
            'SAMLResponse' => base64_encode('<not-saml/>'),
        ]);

        $this->assertGreaterThanOrEqual(400, $browser->getResponse()->getStatusCode());
    }

    public function testAnUnsolicitedResponseWithoutARequestIsNotAccepted(): void
    {
        // No login was started from this browser, so there is nothing for a response to answer.
        $browser = $this->browser();
        $browser->followRedirects(false);
        $browser->request('POST', 'http://'.self::ECHO.':'.self::PORT.'/_drawbridge/sso/postResponse', [
            'SAMLResponse' => base64_encode('<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"/>'),
            'RelayState' => 'http://'.self::ECHO.':'.self::PORT.'/admin/x',
        ]);

        $this->assertGreaterThanOrEqual(400, $browser->getResponse()->getStatusCode());
        $this->assertSame([], $browser->getCookieJar()->allValues('http://'.self::ECHO.':'.self::PORT.'/'));
    }

    public function testASessionCookieFromOneHostDoesNotWorkOnAnother(): void
    {
        $browser = $this->browser();
        $this->login($browser, self::ECHO);
        $cookie = $this->cookieHeader($browser, self::ECHO);

        $this->assertContains($this->raw('legacy.apps.test:'.self::PORT, '/', ['Cookie: '.$cookie])['status'], [302, 303]);
        $this->assertContains($this->raw('blog.apps.test:'.self::PORT, '/wp-admin/x', ['Cookie: '.$cookie])['status'], [302, 303]);
    }
}
