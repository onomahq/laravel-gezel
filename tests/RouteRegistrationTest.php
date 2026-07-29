<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Onomahq\Gezel\Contracts\TurnContextProvider;
use Onomahq\Gezel\Support\Viewing;

/** Stands in for a host app's own composer. */
function bindTurnContextProvider(): void
{
    app()->instance(TurnContextProvider::class, new class implements TurnContextProvider
    {
        public function compose(Model $owner, ?Viewing $viewing = null): ?string
        {
            return 'grounding';
        }
    });
}

/**
 * Registers routes/gezel.php against a throwaway Router. The booted app has
 * already registered its own copy at boot, so flipping config in a test cannot move
 * it; re-running the file is the only way to see what a given config actually
 * registers.
 */
function gezelRouteNames(bool $turnContextEnabled, array $overrides = []): array
{
    config()->set('gezel.turn_context.enabled', $turnContextEnabled);

    foreach ($overrides as $key => $value) {
        config()->set($key, $value);
    }

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

it('registers turn-context once the app opts in and binds a provider', function () {
    bindTurnContextProvider();

    expect(gezelRouteNames(true))->toContain('gezel.turn-context');
});

/**
 * The flag on its own leaves the null default in place, and a route standing on
 * that answers {turn_context: null} to every relayed turn. The middleware relays
 * the turn anyway — grounding is optional by contract — so the miswiring never
 * surfaces. Dropping the route turns it into the 404 the caller already handles.
 */
it('withholds turn-context when only the null default is bound, so a miswired app 404s instead of composing nothing', function () {
    expect(gezelRouteNames(true))->not->toContain('gezel.turn-context');
});

it('withholds turn-context when the flag is off, even with a provider bound', function () {
    bindTurnContextProvider();

    expect(gezelRouteNames(false))->not->toContain('gezel.turn-context');
});

it('registers the callback routes by default, so mounting the package is enough', function () {
    expect(gezelRouteNames(false))
        ->toContain('gezel.agent-messages')
        ->toContain('gezel.principals.verify')
        ->toContain('gezel.usage');
});

/**
 * A host app that serves one of these paths itself shadows the package's route
 * silently, since Laravel keys on method+URI and app routes load last. Deleting
 * the host's route then activates the package's underneath rather than leaving a
 * 404 — a different response shape and different auth, with nothing failing. The
 * host opts out instead, so the deletion produces the 404 it should.
 */
it('drops agent-messages when the host app serves that path itself', function () {
    expect(gezelRouteNames(false, ['gezel.routes.agent_messages' => false]))
        ->not->toContain('gezel.agent-messages')
        ->toContain('gezel.principals.verify')
        ->toContain('gezel.usage');
});

it('drops principals/verify when the host app serves that path itself', function () {
    expect(gezelRouteNames(false, ['gezel.routes.principals_verify' => false]))
        ->not->toContain('gezel.principals.verify')
        ->toContain('gezel.agent-messages')
        ->toContain('gezel.usage');
});

it('keeps the usage route even with both opt-outs, because a 404 dead-letters billing data', function () {
    expect(gezelRouteNames(false, [
        'gezel.routes.agent_messages' => false,
        'gezel.routes.principals_verify' => false,
    ]))->toContain('gezel.usage');
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
