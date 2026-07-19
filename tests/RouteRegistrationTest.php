<?php

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * Registers routes/gezel.php against a throwaway Router. The booted app has
 * already registered its own copy at boot, so flipping config in a test cannot move
 * it; re-running the file is the only way to see what a given config actually
 * registers.
 */
function gezelRouteNames(bool $turnContextEnabled): array
{
    config()->set('gezel.turn_context.enabled', $turnContextEnabled);

    $router = new Router(app('events'), app());
    $original = Route::getFacadeRoot();
    Route::swap($router);

    try {
        require __DIR__.'/../routes/gezel.php';
    } finally {
        Route::swap($original);
    }

    return collect($router->getRoutes())->map(fn ($route) => $route->getName())->filter()->values()->all();
}

it('does not register the turn-context route by default, because it is opt-in', function () {
    expect(gezelRouteNames(false))->not->toContain('gezel.turn-context');
});

it('registers turn-context once the app opts in', function () {
    expect(gezelRouteNames(true))->toContain('gezel.turn-context');
});

it('always registers the routes that are not opt-in', function () {
    expect(gezelRouteNames(false))
        ->toContain('gezel.agent-messages')
        ->toContain('gezel.principals.verify')
        ->toContain('gezel.usage');
});

it('registers the usage route even with usage disabled, so callbacks never 404 into the dead-letter queue', function () {
    config()->set('gezel.usage.enabled', false);

    expect(gezelRouteNames(false))->toContain('gezel.usage');
});

it('names every route under the gezel. prefix the validation rescue keys on', function () {
    foreach (gezelRouteNames(true) as $name) {
        expect($name)->toStartWith('gezel.');
    }
});
