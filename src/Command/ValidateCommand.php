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

        // The secret's variable name and whether it is set here. Never its value.
        $oidc = $config->oidc;
        $secret = getenv($oidc->clientSecretEnv);
        $output->writeln(sprintf('OpenID Connect provider: %s', $oidc->issuer));
        $output->writeln(sprintf(' - metadata: %s', $oidc->metadataUrl));
        $output->writeln(sprintf(' - client id: %s', $oidc->clientId));
        $output->writeln(sprintf(' - client secret: from $%s (%s in this environment)', $oidc->clientSecretEnv, is_string($secret) && $secret !== '' ? 'set' : 'not set'));
        $output->writeln(sprintf(' - user claim: %s', $oidc->userClaim));
        $output->writeln(sprintf(' - attribute claims: %s', $this->pairs($oidc->attributeClaims)));
        $output->writeln(sprintf(' - extra claims: %s', $oidc->extraClaims === [] ? 'none' : implode(', ', $oidc->extraClaims)));
        $output->writeln(sprintf(' - amr map: %s', $this->pairs(array_map(static fn(array $to): string => '[' . implode(',', $to) . ']', $oidc->amrMap))));

        foreach ($config->apps as $app) {
            $contexts = [];

            foreach ($app->rules as $rule) {
                if ($rule->authContext !== null) {
                    $contexts[] = $rule->path . ' ' . $rule->authContext;
                }
            }

            $output->writeln(sprintf(
                ' - %s (%s): default %s, %d path rule(s), mode %s%s',
                $app->id,
                $app->host,
                $app->default->value,
                count($app->rules),
                $app->mode,
                $contexts === [] ? '' : ', auth context: ' . implode(', ', $contexts),
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, string>  $map
     */
    private function pairs(array $map): string
    {
        $out = [];

        foreach ($map as $key => $value) {
            $out[] = $key . '=' . $value;
        }

        return $out === [] ? 'none' : implode(', ', $out);
    }
}
