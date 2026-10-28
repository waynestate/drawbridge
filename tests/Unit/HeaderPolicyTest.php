<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Drawbridge\Http\HeaderPolicy;
use Waynestate\Drawbridge\Tests\Support\Fixtures;

final class HeaderPolicyTest extends TestCase
{
    private function policy(): HeaderPolicy
    {
        $config = Fixtures::config();

        return HeaderPolicy::fromConfig($config->gateway, $config->oidc);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function names(): array
    {
        return [
            'the user header' => ['X-Authenticated-User', true],
            'any other X-Authenticated header' => ['X-Authenticated-Whatever', true],
            'lower case' => ['x-authenticated-user', true],
            'shouting' => ['X-AUTHENTICATED-USER', true],
            'underscores (PHP reads these as the same header)' => ['X_Authenticated_User', true],
            'mixed underscores' => ['X-Authenticated_User', true],
            'the assertion header' => ['X-Sesame-Assertion', true],
            'a different SESAME header' => ['X-Sesame-Anything', true],
            'a DRAWBRIDGE header' => ['X-Drawbridge-Session', true],
            'Remote-User' => ['Remote-User', true],
            'X-Remote-User' => ['X-Remote-User', true],
            'REMOTE_USER as a header' => ['REMOTE_USER', true],
            'oauth2-proxy style' => ['X-Auth-Request-Email', true],
            'forwarded-for is overwritten, not trusted' => ['X-Forwarded-For', true],
            'forwarded proto' => ['X-Forwarded-Proto', true],
            'Forwarded' => ['Forwarded', true],
            'X-Real-IP' => ['X-Real-IP', true],
            'surrounding whitespace' => [' X-Authenticated-User ', true],
            'a mod_auth_openidc claim header' => ['OIDC_CLAIM_sub', true],
            'any claim under that prefix' => ['OIDC-Claim-Made-Up', true],
            'the access token' => ['OIDC_access_token', true],
            'the ID token payload' => ['OIDC_id_token_payload', true],
            'the refresh token' => ['oidc-refresh-token', true],
            'userinfo' => ['OIDC_userinfo_json', true],
            'Cookie is not ours' => ['Cookie', false],
            'Authorization is not ours' => ['Authorization', false],
            'Accept' => ['Accept', false],
            'a name that merely contains authenticated' => ['X-Not-Authenticated-User', false],
            'a name that ends like a prefix' => ['X-Authenticated', false],
        ];
    }

    #[DataProvider('names')]
    public function testShouldStrip(string $name, bool $expected): void
    {
        $this->assertSame($expected, $this->policy()->shouldStrip($name));
    }

    public function testConfiguredNamesAreStrippedToo(): void
    {
        $config = Fixtures::configArray();
        $config['gateway']['assertion_header'] = 'X-Custom-Assertion';
        $config['gateway']['strip_headers'] = ['X-Corp-Identity', 'X-Corp-Claim-'];
        $policy = HeaderPolicy::fromConfig(\Waynestate\Drawbridge\Config\ConfigLoader::fromArray($config)->gateway);

        $this->assertTrue($policy->shouldStrip('x_custom_assertion'));
        $this->assertTrue($policy->shouldStrip('X-Corp-Identity'));
        $this->assertTrue($policy->shouldStrip('X_CORP_CLAIM_EMAIL'));
        $this->assertFalse($policy->shouldStrip('X-Corp-Other'));
    }

    public function testTheNamesTheGuardRemovesCoverEverySpellingOfTheGatewayOwnHeaders(): void
    {
        $names = $this->policy()->expandedNames();

        foreach ([
            'x-sesame-assertion', 'x_sesame_assertion',
            'x-authenticated-user', 'x_authenticated_user', 'x-authenticated_user', 'x_authenticated-user',
            'x-authenticated-email', 'x-authenticated-name', 'x-authenticated-affiliations', 'x_authenticated_affiliations',
            'remote-user', 'remote_user', 'x-remote-user', 'x_remote_user',
            'x-forwarded-for', 'x_forwarded_for', 'forwarded', 'proxy',
            'x-authenticated-role', 'x_authenticated_role',
            'oidc_claim_sub', 'oidc-claim-sub', 'oidc_claim_preferred_username', 'oidc_claim_email', 'oidc_claim_wsuroles',
            'oidc_claim_acrs', 'oidc_claim_amr', 'oidc_claim_affiliations',
            'oidc_access_token', 'oidc-access-token', 'oidc_id_token_payload', 'oidc_refresh_token', 'oidc_userinfo_json',
        ] as $expected) {
            $this->assertContains($expected, $names, $expected);
        }
    }

    public function testEveryNameInTheRemovalListIsOneThePolicyWouldStrip(): void
    {
        $policy = $this->policy();

        foreach ($policy->expandedNames() as $name) {
            $this->assertTrue($policy->shouldStrip($name), $name);
            $this->assertSame(strtolower($name), $name);
        }
    }

    public function testTheRemovalListHasNoDuplicatesAndStaysSmall(): void
    {
        $names = $this->policy()->expandedNames();

        $this->assertSame(count($names), count(array_unique($names)));
        $this->assertLessThan(2000, count($names));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function connections(): array
    {
        return [
            'close' => ['close', 'close'],
            'keep-alive' => ['Keep-Alive', 'keep-alive'],
            'upgrade for websockets' => ['keep-alive, Upgrade', 'keep-alive, upgrade'],
            'a gateway header named as a hop-by-hop header' => ['X-Sesame-Assertion', ''],
            'mixed with a harmless token' => ['close, X-Authenticated-User', 'close'],
            'underscore spelling' => ['X_Authenticated_User, keep-alive', 'keep-alive'],
            'forwarded headers cannot be dropped through Connection' => ['X-Forwarded-For', ''],
            'unknown tokens are dropped' => ['TE, Foo', ''],
            'duplicates collapse' => ['close, close', 'close'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('connections')]
    public function testFilterConnection(string $value, string $expected): void
    {
        $this->assertSame($expected, $this->policy()->filterConnection($value));
    }
}
