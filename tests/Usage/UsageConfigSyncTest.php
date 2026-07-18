<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;
use Onomahq\Gezel\Usage\UsageConfigSync;

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

it('pushes the usage payload to the container config endpoint with a microsecond version', function () {
    Http::fake(['http://middleware.test/*' => Http::response(['pushed' => true])]);

    $owner = GezelUser::create(['name' => 'Ada']);
    $gezelId = $owner->ensureGezelId();

    app(UsageConfigSync::class)->sync($owner->refresh());

    Http::assertSent(function ($request) use ($gezelId) {
        $body = $request->data();

        return $request->url() === "http://middleware.test/v1/containers/{$gezelId}/config"
            && $body['version'] > 1_000_000_000_000_000  // microsecond epoch, not seconds
            && $body['payload']['usage']['monthly_cap_usd'] === 20.0
            && $body['payload']['usage']['pricing_version'] === 1
            && array_key_exists('anthropic/claude-sonnet-5', $body['payload']['usage']['prices']);
    });
});

it('prefers the per-owner cap override over the configured default', function () {
    Http::fake(['http://middleware.test/*' => Http::response(['pushed' => true])]);

    $owner = GezelUser::create(['name' => 'Ada', 'usage_cap_usd' => 55.5]);
    $owner->ensureGezelId();

    app(UsageConfigSync::class)->sync($owner->refresh());

    Http::assertSent(fn ($request) => $request->data()['payload']['usage']['monthly_cap_usd'] === 55.5);
});

it('is a no-op for an owner without a gezel_id', function () {
    Http::fake();

    $owner = GezelUser::create(['name' => 'Ada']);

    app(UsageConfigSync::class)->sync($owner);

    Http::assertNothingSent();
});

it('strictly increases the version across sequential syncs, which the no-lock design rests on', function () {
    Http::fake(['http://middleware.test/*' => Http::response(['pushed' => true])]);

    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $owner->refresh();

    $sync = app(UsageConfigSync::class);
    $sync->sync($owner);
    usleep(2);
    $sync->sync($owner);

    $versions = [];
    Http::assertSent(function ($request) use (&$versions) {
        $versions[] = $request->data()['version'];

        return true;
    });

    expect($versions)->toHaveCount(2)
        ->and($versions[1])->toBeGreaterThan($versions[0]);
});

it('falls back to the configured default cap when the usage migration has not run yet', function () {
    // Staged adoption: package upgraded, add_gezel_usage not yet migrated.
    Schema::table('users', fn ($table) => $table->dropColumn('usage_cap_usd'));

    Http::fake(['http://middleware.test/*' => Http::response(['pushed' => true])]);

    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    app(UsageConfigSync::class)->sync($owner->refresh());

    Http::assertSent(fn ($request) => $request->data()['payload']['usage']['monthly_cap_usd'] === 20.0);
});
