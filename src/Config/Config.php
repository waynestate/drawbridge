<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Config;

/**
 * The whole validated gateway config.
 */
final readonly class Config
{
    /**
     * @param  list<AppConfig>  $apps
     */
    public function __construct(
        public GatewayConfig $gateway,
        public array $apps,
    ) {}

    /**
     * The app for a Host header value, matched exactly and case-insensitively. A port is ignored.
     */
    public function appForHost(string $host): ?AppConfig
    {
        $host = strtolower(preg_replace('/:\d+$/', '', trim($host)) ?? '');

        foreach ($this->apps as $app) {
            if ($app->host === $host) {
                return $app;
            }
        }

        return null;
    }
}
