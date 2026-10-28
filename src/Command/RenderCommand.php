<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Drawbridge\Config\ConfigException;
use Waynestate\Drawbridge\Config\ConfigLoader;
use Waynestate\Drawbridge\Render\ApacheRenderer;
use Waynestate\Drawbridge\Render\LuaPolicyRenderer;
use Waynestate\Drawbridge\Render\RenderOptions;

/**
 * Validates the config, then writes the Apache virtual hosts and the Lua policy table.
 *
 * Nothing is written unless the whole config is valid, so a bad edit leaves the
 * last good files in place and the container refuses to start (fail closed).
 */
final class RenderCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('render')
            ->setDescription('Render the Apache config and the Lua policy from the gateway config.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the config. Default: DRAWBRIDGE_CONFIG.')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Where to write apps.conf and policy.lua.', '/tmp/drawbridge/generated');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configFile = Env::configPath($input);

        try {
            $config = ConfigLoader::fromFile($configFile);
            $env = Env::all();
            $env['DRAWBRIDGE_CONFIG'] = $configFile;
            $options = RenderOptions::fromEnvironment($env, (string) $input->getOption('output-dir'));
        } catch (ConfigException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        try {
            $apache = (new ApacheRenderer())->render($config, $options);
            $lua = (new LuaPolicyRenderer())->render($config);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if (! is_dir($options->outputDir) && ! mkdir($options->outputDir, 0750, true)) {
            $output->writeln('<error>Could not create "' . $options->outputDir . '".</error>');

            return Command::FAILURE;
        }

        foreach (['apps.conf' => $apache, 'policy.lua' => $lua] as $name => $contents) {
            if (file_put_contents($options->outputDir . '/' . $name, $contents) === false) {
                $output->writeln('<error>Could not write "' . $options->outputDir . '/' . $name . '".</error>');

                return Command::FAILURE;
            }
        }

        $output->writeln(sprintf('Rendered %d app(s) into %s.', count($config->apps), $options->outputDir));

        return Command::SUCCESS;
    }
}
