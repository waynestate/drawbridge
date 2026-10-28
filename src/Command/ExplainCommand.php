<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Drawbridge\Config\ConfigException;
use Waynestate\Drawbridge\Config\ConfigLoader;
use Waynestate\Drawbridge\Http\PathNormalizer;

/**
 * Says what the gateway would do with a host and path. Handy before a deploy, and in a review.
 */
final class ExplainCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('explain')
            ->setDescription('Show what the gateway does with a request for a host and a path.')
            ->addArgument('host', InputArgument::REQUIRED, 'The app host.')
            ->addArgument('path', InputArgument::REQUIRED, 'The request path, as sent (the query string is ignored).')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the config. Default: DRAWBRIDGE_CONFIG.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = ConfigLoader::fromFile(Env::configPath($input));
        } catch (ConfigException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $host = (string) $input->getArgument('host');
        $path = (string) $input->getArgument('path');
        $app = $config->appForHost($host);

        if ($app === null) {
            $output->writeln("No app is configured for \"{$host}\": the gateway answers 403.");

            return Command::SUCCESS;
        }

        $normalized = PathNormalizer::normalize($path);

        if ($normalized === null) {
            $output->writeln("\"{$path}\" is refused as unsafe to route, so it is treated as login-required.");

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('%s %s => %s (matched as %s)', $app->id, $path, $app->protectionFor($path)->value, $normalized));

        return Command::SUCCESS;
    }
}
