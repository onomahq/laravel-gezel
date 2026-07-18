<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class);
    migrateGezelUsageTables();
    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token');
});

afterEach(function () {
    Schema::dropIfExists('gezel_usage_events');
    Schema::dropIfExists('users');
});

function provisionedOwner(string $name): GezelUser
{
    $owner = GezelUser::create(['name' => $name, 'gezel_provisioned_at' => now()]);
    $owner->ensureGezelId();

    return $owner->refresh();
}

it('pushes a config for every provisioned owner and skips the unprovisioned', function () {
    Http::fake(['http://middleware.test/*' => Http::response(['pushed' => true])]);

    $provisioned = provisionedOwner('Ada');
    GezelUser::create(['name' => 'Grace']);  // never provisioned

    $this->artisan('gezel:sync-usage-config')
        ->expectsOutputToContain('synced for 1 owner(s), 0 failed')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), $provisioned->gezel_id));
});

it('filters to one owner with --owner-id', function () {
    Http::fake(['http://middleware.test/*' => Http::response(['pushed' => true])]);

    $first = provisionedOwner('Ada');
    provisionedOwner('Grace');

    $this->artisan('gezel:sync-usage-config', ['--owner-id' => $first->getKey()])
        ->expectsOutputToContain('synced for 1 owner(s)')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('keeps going past a failing owner and reports failure', function () {
    $first = provisionedOwner('Ada');
    $second = provisionedOwner('Grace');

    Http::fake([
        "http://middleware.test/v1/containers/{$first->gezel_id}/config" => Http::response('nope', 500),
        "http://middleware.test/v1/containers/{$second->gezel_id}/config" => Http::response(['pushed' => true]),
    ]);

    $this->artisan('gezel:sync-usage-config')
        ->expectsOutputToContain('synced for 1 owner(s), 1 failed')
        ->assertFailed();
});

it('does nothing when usage is disabled', function () {
    Http::fake();
    config()->set('gezel.usage.enabled', false);

    provisionedOwner('Ada');

    $this->artisan('gezel:sync-usage-config')->assertSuccessful();

    Http::assertNothingSent();
});
