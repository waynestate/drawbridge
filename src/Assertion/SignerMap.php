<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Assertion;

use Throwable;

/**
 * The long-running side of Apache's RewriteMap "prg:" protocol.
 *
 * Apache writes one line (a signing request) and reads back exactly one line:
 * a compact JWT, or the word NULL. NULL is what Apache sees when anything is
 * wrong, and the vhost config turns NULL into a 503, so a signer that cannot
 * sign never lets a request through unsigned (fail closed).
 *
 * Nothing about the request goes to the log beyond a reason code: not the
 * subject, not the attributes, not the token.
 */
final class SignerMap
{
    public const FAILED = 'NULL';

    /**
     * @param  resource|null  $log  Where reason codes go; STDERR by default (Apache sends it to the error log).
     */
    public function __construct(
        private readonly AssertionSigner $signer,
        private mixed $log = null,
    ) {
        $this->log ??= STDERR;
    }

    public function handle(string $line): string
    {
        try {
            return $this->signer->sign(SigningRequest::fromLine($line));
        } catch (InvalidSigningRequest $e) {
            $this->report('refused: ' . $e->getMessage());
        } catch (Throwable $e) {
            $this->report('failed: ' . $e::class);
        }

        return self::FAILED;
    }

    /**
     * Answer lines until the input ends. Apache closes the pipe at shutdown.
     *
     * @param  resource  $input
     * @param  resource  $output
     */
    public function serve($input, $output): void
    {
        while (($line = fgets($input)) !== false) {
            fwrite($output, $this->handle($line) . "\n");
            fflush($output);
        }
    }

    private function report(string $reason): void
    {
        if (is_resource($this->log)) {
            fwrite($this->log, '[drawbridge-signer] ' . $reason . "\n");
        }
    }
}
