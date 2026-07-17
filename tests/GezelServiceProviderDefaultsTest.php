<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Onomahq\Gezel\Contracts\AgentMessageHandler;
use Onomahq\Gezel\Contracts\OwnerMembershipVerifier;
use Onomahq\Gezel\Contracts\TargetOwnershipVerifier;
use Onomahq\Gezel\Contracts\TurnContextProvider;
use Onomahq\Gezel\Defaults\AlwaysAllowMembershipVerifier;
use Onomahq\Gezel\Defaults\DeniesUnverifiableTargets;
use Onomahq\Gezel\Defaults\FiresGezelAgentMessageReceived;
use Onomahq\Gezel\Defaults\NullTurnContextProvider;

it('binds a default for every hook a fresh install needs', function () {
    expect($this->app->make(AgentMessageHandler::class))->toBeInstanceOf(FiresGezelAgentMessageReceived::class);
    expect($this->app->make(TurnContextProvider::class))->toBeInstanceOf(NullTurnContextProvider::class);
    expect($this->app->make(OwnerMembershipVerifier::class))->toBeInstanceOf(AlwaysAllowMembershipVerifier::class);
    expect($this->app->make(TargetOwnershipVerifier::class))->toBeInstanceOf(DeniesUnverifiableTargets::class);
});

it('limits gezel-internal to 600 a minute per ip and 120 per principal', function () {
    $limits = RateLimiter::limiter('gezel-internal')(Request::create('/x', 'POST'));

    expect($limits)->toHaveCount(2);
    expect($limits[0])->toBeInstanceOf(Limit::class);
    expect($limits[0]->maxAttempts)->toBe(600);
    expect($limits[0]->key)->toStartWith('gezel-ip:');
    expect($limits[1]->maxAttempts)->toBe(120);
    expect($limits[1]->key)->toBe('gezel-principal:unresolved');
});

it('limits gezel-verify by ip alone, since it has no principal to key on yet', function () {
    // A per-principal limit here would put every container's verification in
    // the one 'unresolved' bucket and cap them collectively.
    $limit = RateLimiter::limiter('gezel-verify')(Request::create('/x', 'POST'));

    expect($limit)->toBeInstanceOf(Limit::class);
    expect($limit->maxAttempts)->toBe(600);
    expect($limit->key)->toStartWith('gezel-ip:');
});
