<?php

declare(strict_types=1);

namespace Esanj\AuthBridge;

use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use Esanj\AuthBridge\Services\AuthBridgeService;
use Esanj\AuthBridge\Services\ClientCredentialsService;
use Illuminate\Support\ServiceProvider;

class AuthBridgeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/auth_bridge.php' => config_path('esanj.auth_bridge.php'),
        ], 'esanj-auth-bridge-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/auth_bridge.php',
            'esanj.auth_bridge'
        );

        $this->registerServices();
    }

    private function registerServices(): void
    {
        $this->app->singleton(AuthBridgeServiceInterface::class, function ($app) {
            return new AuthBridgeService();
        });

        $this->app->singleton(ClientCredentialsServiceInterface::class, function ($app) {
            return new ClientCredentialsService();
        });
    }
}
