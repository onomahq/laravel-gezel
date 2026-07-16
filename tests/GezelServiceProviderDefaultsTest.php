<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Onomahq\Gezel\Contracts\AgentMessageHandler;
use Onomahq\Gezel\Contracts\OwnerMembershipVerifier;
use Onomahq\Gezel\Contracts\TurnContextProvider;
use Onomahq\Gezel\Defaults\AlwaysAllowMembershipVerifier;
use Onomahq\Gezel\Defaults\FiresGezelAgentMessageReceived;
use Onomahq\Gezel\Defaults\NullTurnContextProvider;

it('binds the default AgentMessageHandler, TurnContextProvider, and OwnerMembershipVerifier', function () {
    expect($this->app->make(AgentMessageHandler::class))->toBeInstanceOf(FiresGezelAgentMessageReceived::class);
    expect($this->app->make(TurnContextProvider::class))->toBeInstanceOf(NullTurnContextProvider::class);
    expect($this->app->make(OwnerMembershipVerifier::class))->toBeInstanceOf(AlwaysAllowMembershipVerifier::class);
});

it('registers the gezel-internal rate limiter with an IP limit and a principal limit', function () {
    $limiter = RateLimiter::limiter('gezel-internal');

    expect($limiter)->not->toBeNull();

    $limits = $limiter(Request::create('/x', 'POST'));

    expect($limits)->toHaveCount(2);
    expect($limits[0])->toBeInstanceOf(Limit::class);
    expect($limits[1])->toBeInstanceOf(Limit::class);
});
