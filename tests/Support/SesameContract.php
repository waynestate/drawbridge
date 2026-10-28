<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Support;

use DateInterval;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;

/**
 * The checks SESAME's GatewayDriver makes on an assertion, written out with the same library calls.
 *
 * SESAME (sesame/src/Drivers/GatewayDriver.php) parses the JWT, then asserts
 * SignedWith (algorithm pinned), IssuedBy, PermittedFor and LooseValidAt with a
 * leeway, and requires iat, exp and a non-empty sub. This class repeats that
 * so the unit suite can prove the signer's output passes without needing the
 * SESAME checkout. The real driver is exercised too, by RealSesameTest,
 * whenever SESAME_PATH points at a SESAME checkout that has been installed.
 */
final class SesameContract
{
    /**
     * @return array<string, mixed>|null The claims when SESAME would accept the token; null when it would refuse it.
     */
    public static function accepts(string $token, string $publicKeyPem, string $issuer, string $audience, ClockInterface $clock, int $leewaySeconds = 30): ?array
    {
        try {
            $parsed = (new Parser(new JoseEncoder()))->parse($token);

            if (! $parsed instanceof UnencryptedToken) {
                return null;
            }

            (new Validator())->assert(
                $parsed,
                new SignedWith(new Sha256(), InMemory::plainText($publicKeyPem)),
                new IssuedBy($issuer),
                new PermittedFor($audience),
                new LooseValidAt($clock, new DateInterval('PT' . $leewaySeconds . 'S')),
            );
        } catch (RequiredConstraintsViolated|\Throwable) {
            return null;
        }

        $claims = $parsed->claims();

        foreach (['iat', 'exp', 'sub'] as $required) {
            if (! $claims->has($required)) {
                return null;
            }
        }

        $subject = $claims->get('sub');

        if (! is_string($subject) || $subject === '') {
            return null;
        }

        return $claims->all();
    }
}
