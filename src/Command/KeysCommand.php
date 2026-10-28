<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Command;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Drawbridge\Assertion\KeyPair;

/**
 * Generates an ES256 signing key pair for a local run or the test rig. Never overwrites.
 */
final class KeysCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('keys:generate')
            ->setDescription('Generate an ES256 (P-256) assertion signing key pair. Refuses to overwrite.')
            ->addOption('private', null, InputOption::VALUE_REQUIRED, 'Where to write the private key.')
            ->addOption('public', null, InputOption::VALUE_REQUIRED, 'Where to write the public key.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $private = $input->getOption('private');
        $public = $input->getOption('public');

        if (! is_string($private) || $private === '' || ! is_string($public) || $public === '') {
            $output->writeln('<error>Give both --private=<file> and --public=<file>.</error>');

            return Command::INVALID;
        }

        try {
            KeyPair::write($private, $public);
        } catch (RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln("Wrote {$private} (keep it secret) and {$public} (give it to the apps' SESAME config).");

        return Command::SUCCESS;
    }
}
