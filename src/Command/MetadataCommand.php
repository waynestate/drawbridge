<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Command;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Drawbridge\Config\ConfigException;
use Waynestate\Drawbridge\Config\ConfigLoader;
use Waynestate\Drawbridge\Render\MetadataRenderer;

/**
 * Prints the combined SP metadata to register with the IdP.
 */
final class MetadataCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('sp:metadata')
            ->setDescription('Print the one SP metadata document for all apps (register this with the IdP).')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the config. Default: DRAWBRIDGE_CONFIG.')
            ->addOption('cert', null, InputOption::VALUE_REQUIRED, 'The SP certificate (PEM). Default: DRAWBRIDGE_SP_CERT_FILE.')
            ->addOption('scheme', null, InputOption::VALUE_REQUIRED, 'The public scheme.', 'https')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'The public port, when it is not the default.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $certFile = $input->getOption('cert');

        if (! is_string($certFile) || $certFile === '') {
            $env = getenv('DRAWBRIDGE_SP_CERT_FILE');
            $certFile = is_string($env) && $env !== '' ? $env : '/run/drawbridge/sp.crt';
        }

        $port = $input->getOption('port');

        try {
            $config = ConfigLoader::fromFile(Env::configPath($input));
            $pem = is_readable($certFile) ? file_get_contents($certFile) : false;

            if ($pem === false) {
                throw new RuntimeException("The certificate \"{$certFile}\" cannot be read.");
            }

            $xml = (new MetadataRenderer())->render($config, $pem, (string) $input->getOption('scheme'), is_string($port) ? (int) $port : null);
        } catch (ConfigException|RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->write($xml);

        return Command::SUCCESS;
    }
}
