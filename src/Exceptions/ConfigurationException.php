<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Exceptions;

class ConfigurationException extends AuthBridgeException
{
    public static function invalidBaseUrl(string $baseUrl): self
    {
        return new self(
            message: 'esanj.auth_bridge.base_url is not a valid URL. Set ACCOUNTING_BRIDGE_BASE_URL in your .env '
                . 'and run php artisan config:clear.',
            code: 500,
            context: ['base_url' => $baseUrl]
        );
    }

    public static function insecureBaseUrl(string $baseUrl): self
    {
        return new self(
            message: 'esanj.auth_bridge.base_url must use HTTPS in production, or client_secret travels in '
                . 'cleartext. Set ACCOUNTING_BRIDGE_ALLOW_INSECURE_BASE_URL=true only when the OAuth server is '
                . 'reached over a trusted private network.',
            code: 500,
            context: ['base_url' => $baseUrl]
        );
    }

    public static function missingCredentials(): self
    {
        return new self(
            message: 'esanj.auth_bridge client_id/client_secret are not configured. Set '
                . 'ACCOUNTING_BRIDGE_CLIENT_ID and ACCOUNTING_BRIDGE_CLIENT_SECRET in your .env and run '
                . 'php artisan config:clear.',
            code: 500
        );
    }
}
