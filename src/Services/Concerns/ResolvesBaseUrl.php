<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Services\Concerns;

use Esanj\AuthBridge\Exceptions\ConfigurationException;

trait ResolvesBaseUrl
{
    private function resolveBaseUrl(): string
    {
        $config = (array) (config('esanj.auth_bridge') ?? []);

        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');

        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw ConfigurationException::invalidBaseUrl($baseUrl);
        }

        $allowInsecure = (bool) ($config['allow_insecure_base_url'] ?? false);

        if (!$allowInsecure && app()->isProduction() && !str_starts_with($baseUrl, 'https://')) {
            throw ConfigurationException::insecureBaseUrl($baseUrl);
        }

        return $baseUrl;
    }
}
