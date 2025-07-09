<?php

namespace Esanj\AuthBridge;

use Illuminate\Support\ServiceProvider;

class AuthBridgeServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/config/auth_bridge.php' => config_path('auth_bridge.php'),
        ], 'config');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/auth_bridge.php', 'auth_bridge'
        );
    }
}
