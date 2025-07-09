<?php

use Esanj\AuthBridge\Http\Controllers\AuthBridgeController;
use Illuminate\Support\Facades\Route;

Route::group(config('auth_bridge.routes'), function () {

    Route::get(config('auth_bridge.route_path.redirect'), [AuthBridgeController::class, 'redirect'])
        ->name('auth-bridge.redirect');

    Route::get(config('auth_bridge.route_path.callback'), [AuthBridgeController::class, 'callback'])
        ->name('auth-bridge.callback');
});
