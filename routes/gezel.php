<?php

use Illuminate\Support\Facades\Route;
use Onomahq\Gezel\GezelServiceProvider;
use Onomahq\Gezel\Http\Controllers\AgentMessagesController;
use Onomahq\Gezel\Http\Controllers\PrincipalsVerifyController;
use Onomahq\Gezel\Http\Controllers\TurnContextController;
use Onomahq\Gezel\Http\Controllers\UsageController;
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
        if (config('gezel.routes.agent_messages', true)) {
            Route::post('/agent-messages', AgentMessagesController::class)
                ->middleware([AuthenticateGezelContainerPrincipal::class, 'throttle:gezel-internal'])
                ->withoutMiddleware('throttle:api')
                ->name('agent-messages');
        }

        // gezel-verify, not gezel-internal: resolving a principal is this
        // endpoint's whole job, so it never has one to key on, and every
        // request would land in the single 'unresolved' bucket. That caps
        // verification for every container at once rather than per caller.
        // The IP ceiling is the limit that makes sense here; the service token
        // is the actual gate.
        if (config('gezel.routes.principals_verify', true)) {
            Route::post('/principals/verify', PrincipalsVerifyController::class)
                ->middleware([VerifyGezelServiceToken::class, 'throttle:gezel-verify'])
                ->withoutMiddleware('throttle:api')
                ->name('principals.verify');
        }

        // The flag alone would not be enough: enabling it without binding a
        // TurnContextProvider leaves the package's own null default in place,
        // which answers {turn_context: null} forever. The middleware treats
        // that as "no grounding" and relays the turn anyway — grounding is
        // optional by contract — so every relayed turn quietly loses its
        // grounding and nothing errors anywhere. No provider, no route, and
        // the middleware gets the 404 it already handles.
        if (GezelServiceProvider::servesTurnContext()) {
            Route::post('/turn-context', TurnContextController::class)
                ->middleware([VerifyGezelServiceToken::class, 'throttle:gezel-internal'])
                ->withoutMiddleware('throttle:api')
                ->name('turn-context');
        }

        // Always registered, even with gezel.usage.enabled = false: the
        // middleware POSTs its ledger to the literal path
        // /api/v1/internal/usage (hardcoded Rust-side) and dead-letters
        // permanently on 404, so an unregistered route silently destroys
        // billing data. gezel:health asserts routes.prefix still lines up.
        Route::post('/usage', UsageController::class)
            ->middleware([VerifyGezelServiceToken::class, 'throttle:gezel-internal'])
            ->withoutMiddleware('throttle:api')
            ->name('usage');
    });
