<?php

declare(strict_types=1);

namespace Esanj\AuthBridge;

use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use Esanj\AuthBridge\Http\Middleware\RefreshExpiredToken;
use Esanj\AuthBridge\Services\AuthBridgeService;
use Esanj\AuthBridge\Services\ClientCredentialsService;
use Esanj\AuthBridge\Support\TokenSessionStore;
use Esanj\AuthBridge\Support\RequestTokenContext;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class AuthBridgeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/auth_bridge.php' => config_path('esanj/auth_bridge.php'),
        ], 'esanj-auth-bridge-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        $this->app->make(Router::class)
            ->aliasMiddleware('auth-bridge.refresh', RefreshExpiredToken::class);
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
        $this->app->scoped(RequestTokenContext::class);

        $this->app->scoped(TokenSessionStore::class, function ($app) {
            return new TokenSessionStore();
        });

        $this->app->scoped(AuthBridgeServiceInterface::class, function ($app) {
            return new AuthBridgeService(
                $app->make(TokenSessionStore::class),
                $app->make(RequestTokenContext::class),
            );
        });

        $this->app->scoped(ClientCredentialsServiceInterface::class, function ($app) {
            return new ClientCredentialsService();
        });
    }
}
