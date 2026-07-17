<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Contracts\TurnContextProvider;
use Onomahq\Gezel\Support\Viewing;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class);
    config()->set('gezel.middleware.service_token', 'the-service-token');
});

afterEach(function () {
    Schema::dropIfExists('users');
});

function turnContextUri(): string
{
    return '/'.trim(config('gezel.routes.prefix'), '/').'/turn-context';
}

it('404s without the service token', function () {
    $this->postJson(turnContextUri(), ['user_id' => 'whatever'])
        ->assertNotFound()
        ->assertExactJson(['error' => 'not found']);
});

it('refuses an unknown owner with the standard body, not a distinguishable turn_context', function () {
    // Answering an unknown gezel_id differently from a bad token would make
    // this an owner enumeration oracle for anyone holding the service token.
    $badToken = $this->postJson(turnContextUri(), ['user_id' => 'x']);

    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(turnContextUri(), ['user_id' => 'unknown-gezel-id'])
        ->assertNotFound()
        ->assertExactJson($badToken->json());
});

it('resolves the owner by gezel_id and calls the bound TurnContextProvider, whose default answers null', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(turnContextUri(), ['user_id' => $owner->gezel_id])
        ->assertOk()
        ->assertJson(['turn_context' => null]);
});

it('404s a validation failure instead of returning 422', function () {
    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(turnContextUri(), [])
        ->assertNotFound()
        ->assertExactJson(['error' => 'not found']);
});

it('passes a Viewing built from the request into the bound provider', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $captured = null;

    $this->app->bind(TurnContextProvider::class, function () use (&$captured) {
        return new class($captured) implements TurnContextProvider
        {
            public function __construct(private mixed &$captured) {}

            public function compose(Model $owner, ?Viewing $viewing = null): ?string
            {
                $this->captured = $viewing;

                return 'composed context';
            }
        };
    });

    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(turnContextUri(), [
            'user_id' => $owner->gezel_id,
            'viewing' => ['kind' => 'event', 'name' => 'Launch Party', 'id' => 'abc'],
        ])
        ->assertOk()
        ->assertJson(['turn_context' => 'composed context']);

    expect($captured)->toBeInstanceOf(Viewing::class);
    expect($captured->kind)->toBe('event');
    expect($captured->name)->toBe('Launch Party');
    expect($captured->id)->toBe('abc');
});
