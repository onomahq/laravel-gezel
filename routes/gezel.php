<?php

use Illuminate\Support\Facades\Route;
use Onomahq\Gezel\Http\Controllers\AgentMessagesController;
use Onomahq\Gezel\Http\Controllers\PrincipalsVerifyController;
use Onomahq\Gezel\Http\Controllers\TurnContextController;
use Onomahq\Gezel\Http\Middleware\AuthenticateGezelContainerPrincipal;
use Onomahq\Gezel\Http\Middleware\VerifyGezelServiceToken;

// Callbacks from the Gezel middleware. A validation failure inside a
// controller maps to the same uniform refusal as every auth failure here,
// never a browser-style 422, via the renderable() hook GezelServiceProvider
// registers on the exception handler (a wrapping middleware can't catch it:
// Illuminate\Routing\Pipeline renders exceptions to a Response at each slice,
// so they never propagate as a throwable to an outer middleware's try/catch).
// That hook keys on the gezel.* route names below, so it only ever touches
// routes this file registered, never a host app's own routes that happen to
// sit under the same prefix.
//
// Each route's own auth middleware runs before its throttle so the limiter can
// key on the principal that middleware resolved.
//
// withoutMiddleware('throttle:api'): routes.middleware defaults to ['api'],
// and an app that opted into Laravel's api limiter via throttleApi() has
// 'throttle:api' in that group. Every Gezel callback arrives from one
// middleware IP, so that limiter would cap all containers together. A no-op
// for an app that never opted in.
Route::prefix(config('gezel.routes.prefix'))
    ->middleware(config('gezel.routes.middleware', []))
    ->name('gezel.')
    ->group(function () {
        Route::post('/agent-messages', AgentMessagesController::class)
            ->middleware([AuthenticateGezelContainerPrincipal::class, 'throttle:gezel-internal'])
            ->withoutMiddleware('throttle:api')
            ->name('agent-messages');

        // gezel-verify, not gezel-internal: resolving a principal is this
        // endpoint's whole job, so it never has one to key on, and every
        // request would land in the single 'unresolved' bucket. That caps
        // verification for every container at once rather than per caller.
        // The IP ceiling is the limit that makes sense here; the service token
        // is the actual gate.
        Route::post('/principals/verify', PrincipalsVerifyController::class)
            ->middleware([VerifyGezelServiceToken::class, 'throttle:gezel-verify'])
            ->withoutMiddleware('throttle:api')
            ->name('principals.verify');

        if (config('gezel.turn_context.enabled', false)) {
            Route::post('/turn-context', TurnContextController::class)
                ->middleware([VerifyGezelServiceToken::class, 'throttle:gezel-internal'])
                ->withoutMiddleware('throttle:api')
                ->name('turn-context');
        }
    });
