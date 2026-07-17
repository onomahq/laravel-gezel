<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Tests\Fixtures\DeprovisioningOwner;

beforeEach(function () {
    migrateGezelOwnerTable(DeprovisioningOwner::class);
    migratePersonalAccessTokensTable();

    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token-123');
});

afterEach(function () {
    Schema::dropIfExists('users');
    Schema::dropIfExists('personal_access_tokens');
});

it('no-ops when the owner was never provisioned', function () {
    $owner = DeprovisioningOwner::create(['name' => 'Ada']);

    Http::fake();

    $owner->deprovisionGezelContainer();

    Http::assertNothingSent();
});

it('deprovisions the container and revokes its bearer', function () {
    $owner = DeprovisioningOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $owner->forceFill(['gezel_provisioned_at' => now()])->save();
    $token = $owner->createToken(SanctumIssuer::TOKEN_NAME, ['*']);

    Http::fake([
        "middleware.test/v1/containers/{$owner->gezel_id}" => Http::response([], 200),
    ]);

    $owner->deprovisionGezelContainer();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    expect($owner->tokens()->where('id', $token->accessToken->id)->exists())->toBeFalse();
});

it('still revokes the bearer when the middleware refuses container lifecycle', function () {
    $owner = DeprovisioningOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $owner->forceFill(['gezel_provisioned_at' => now()])->save();
    $token = $owner->createToken(SanctumIssuer::TOKEN_NAME, ['*']);

    Http::fake([
        "middleware.test/v1/containers/{$owner->gezel_id}" => Http::response('Docker not running', 501),
    ]);

    $owner->deprovisionGezelContainer();

    expect($owner->tokens()->where('id', $token->accessToken->id)->exists())->toBeFalse();
});
