<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Tests\Fixtures\SanctumOwner;

beforeEach(function () {
    migrateGezelOwnerTable(SanctumOwner::class);
    migratePersonalAccessTokensTable();

    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token-123');
});

afterEach(function () {
    Schema::dropIfExists('users');
    Schema::dropIfExists('personal_access_tokens');
});

it('reports no provisioned owners', function () {
    $this->artisan('gezel:reconcile-container-bearers')
        ->expectsOutputToContain('No provisioned Gezel owners found.')
        ->assertExitCode(0);
});

it('lists what would be reconciled under --dry-run without minting or calling the middleware', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $owner->forceFill(['gezel_provisioned_at' => now()])->save();
    $oldToken = $owner->createToken(SanctumIssuer::TOKEN_NAME, ['*']);

    Http::fake();

    $this->artisan('gezel:reconcile-container-bearers', ['--dry-run' => true])
        ->expectsOutputToContain("Would reconcile Gezel bearer for owner {$owner->getKey()}")
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect($owner->tokens()->where('id', $oldToken->accessToken->id)->exists())->toBeTrue();
});

it('mints a fresh bearer, recreates the container, and revokes only the previous bearer', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $owner->forceFill(['gezel_provisioned_at' => now()])->save();
    $oldToken = $owner->createToken(SanctumIssuer::TOKEN_NAME, ['*']);
    $unrelated = $owner->createToken('some-other-token');

    Http::fake([
        "middleware.test/v1/containers/{$owner->gezel_id}/recreate" => Http::response([
            'container_id' => 'c-abc',
            'status' => 'provisioned',
        ], 200),
    ]);

    $this->artisan('gezel:reconcile-container-bearers')->assertExitCode(0);

    expect($owner->tokens()->where('id', $oldToken->accessToken->id)->exists())->toBeFalse();
    expect($owner->tokens()->where('id', $unrelated->accessToken->id)->exists())->toBeTrue();
    expect($owner->tokens()->where('name', SanctumIssuer::TOKEN_NAME)->count())->toBe(1);

    Http::assertSent(fn ($request) => $request->url() === "http://middleware.test/v1/containers/{$owner->gezel_id}/recreate");
});

it('scopes to a single owner via --owner', function () {
    $target = SanctumOwner::create(['name' => 'Ada']);
    $target->ensureGezelId();
    $target->forceFill(['gezel_provisioned_at' => now()])->save();

    $other = SanctumOwner::create(['name' => 'Grace']);
    $other->ensureGezelId();
    $other->forceFill(['gezel_provisioned_at' => now()])->save();

    Http::fake(['middleware.test/*' => Http::response(['container_id' => 'c-abc', 'status' => 'provisioned'], 200)]);

    $this->artisan('gezel:reconcile-container-bearers', ['--owner' => $target->getKey()])->assertExitCode(0);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), $target->gezel_id));
});

it('keeps the previous bearer working when the middleware call fails', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $owner->forceFill(['gezel_provisioned_at' => now()])->save();
    $oldToken = $owner->createToken(SanctumIssuer::TOKEN_NAME, ['*']);

    Http::fake(['middleware.test/*' => Http::response('boom', 500)]);

    $this->artisan('gezel:reconcile-container-bearers')->assertExitCode(1);

    expect($owner->tokens()->where('id', $oldToken->accessToken->id)->exists())->toBeTrue();
});
