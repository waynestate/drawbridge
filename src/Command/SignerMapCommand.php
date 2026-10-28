<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Command;

use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Waynestate\Drawbridge\Assertion\AssertionSigner;
use Waynestate\Drawbridge\Assertion\SignerMap;
use Waynestate\Drawbridge\Clock\SystemClock;
use Waynestate\Drawbridge\Config\ConfigException;
use Waynestate\Drawbridge\Config\ConfigLoader;

/**
 * The signer process Apache starts for its RewriteMap. Not for people to run by hand.
 *
 * It loads the config and the private key once, then answers one line per
 * request until Apache closes the pipe. If it cannot start, it exits at once,
 * and Apache answers 503 for every protected path (fail closed).
 */
final class SignerMapCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('signer:map')
            ->setDescription('Run the assertion signer for Apache RewriteMap (reads stdin, writes stdout).')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the config. Default: DRAWBRIDGE_CONFIG.')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The ES256 private key (PEM file).')
            ->addOption('kid', null, InputOption::VALUE_REQUIRED, 'The key ID put in each token header.', 'drawbridge-1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keyFile = $input->getOption('key');

        if (! is_string($keyFile) || ! is_file($keyFile) || ! is_readable($keyFile)) {
            fwrite(STDERR, "[drawbridge-signer] the signing key file cannot be read\n");

            return Command::FAILURE;
        }

        try {
            $config = ConfigLoader::fromFile(Env::configPath($input));
            $signer = new AssertionSigner($config, InMemory::file($keyFile), (string) $input->getOption('kid'), new SystemClock());
        } catch (ConfigException $e) {
            fwrite(STDERR, '[drawbridge-signer] ' . $e->getMessage() . "\n");

            return Command::FAILURE;
        } catch (Throwable $e) {
            fwrite(STDERR, '[drawbridge-signer] could not start: ' . $e::class . "\n");

            return Command::FAILURE;
        }

        (new SignerMap($signer))->serve(STDIN, STDOUT);

        return Command::SUCCESS;
    }
}
