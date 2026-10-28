<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Drawbridge\Config\ConfigException;
use Waynestate\Drawbridge\Config\ConfigLoader;

/**
 * Checks the config file and exits non-zero on any problem. Run it in CI and at container start.
 */
final class ValidateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('config:validate')
            ->setDescription('Validate the gateway config file.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the config (.yaml, .yml or .php). Default: DRAWBRIDGE_CONFIG.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = ConfigLoader::fromFile(Env::configPath($input));
        } catch (ConfigException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('The config is valid: %d app(s).', count($config->apps)));

        foreach ($config->apps as $app) {
            $output->writeln(sprintf(
                ' - %s (%s): default %s, %d path rule(s), mode %s',
                $app->id,
                $app->host,
                $app->default->value,
                count($app->rules),
                $app->mode,
            ));
        }

        return Command::SUCCESS;
    }
}
