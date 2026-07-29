<?php

use Onomahq\Gezel\Http\GezelRefusal;

/**
 * Every way an internal route can say no answers with this exact status and
 * body, so a prober cannot tell a wrong service token from an unknown owner
 * from a rejected membership. The property is indistinguishability, so the
 * status and the body are both load-bearing: a 401 would reveal that the route
 * exists, and any added reason field would name which check failed.
 */
it('refuses with 404, so the route does not confirm it exists', function () {
    expect(GezelRefusal::response()->getStatusCode())->toBe(404);
});

it('carries a body that names no reason', function () {
    expect(GezelRefusal::response()->getData(true))->toBe(['error' => 'not found']);
});

/**
 * The refusals must be byte-identical, not merely both 404 — a difference in
 * body or headers is enough to distinguish two failure paths.
 */
it('answers identically every time, so two failure paths cannot be told apart', function () {
    expect(GezelRefusal::response()->getContent())
        ->toBe(GezelRefusal::response()->getContent());
});
