<?php

use Illuminate\Support\Facades\Route;
use Onomahq\Gezel\Http\Controllers\AgentMessagesController;
use Onomahq\Gezel\Http\Controllers\PrincipalsVerifyController;
use Onomahq\Gezel\Http\Controllers\TurnContextController;
use Onomahq\Gezel\Http\Middleware\AuthenticateGezelContainerPrincipal;
use Onomahq\Gezel\Http\Middleware\VerifyGezelServiceToken;

// Callbacks from the Gezel middleware. A validation failure inside a
// controller maps to the same uniform 404 as every other auth failure here
// — never a browser-style 422 — via the renderable() hook GezelServiceProvider
// registers on the exception handler (a wrapping middleware can't catch it:
// Illuminate\Routing\Pipeline renders exceptions to a Response at each slice,
// so they never propagate as a throwable to an outer middleware's try/catch).
// Each route's own auth middleware runs before 'throttle:gezel-internal' so
// the limiter can key on the principal that middleware resolved.
Route::prefix(config('gezel.routes.prefix'))
    ->middleware(config('gezel.routes.middleware', []))
    ->group(function () {
        Route::post('/agent-messages', AgentMessagesController::class)
            ->middleware([AuthenticateGezelContainerPrincipal::class, 'throttle:gezel-internal'])
            ->withoutMiddleware('throttle:api');

        Route::post('/principals/verify', PrincipalsVerifyController::class)
            ->middleware([VerifyGezelServiceToken::class, 'throttle:gezel-internal'])
            ->withoutMiddleware('throttle:api');

        if (config('gezel.turn_context.enabled', false)) {
            Route::post('/turn-context', TurnContextController::class)
                ->middleware([VerifyGezelServiceToken::class, 'throttle:gezel-internal'])
                ->withoutMiddleware('throttle:api');
        }
    });
