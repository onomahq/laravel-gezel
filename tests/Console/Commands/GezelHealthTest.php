<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Tests\Fixtures\SanctumOwner;

beforeEach(function () {
    migrateGezelOwnerTable(SanctumOwner::class);

    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token-123');
    config()->set('gezel.app_id', 'onoma');
});

afterEach(function () {
    Schema::dropIfExists('users');
});

it('fails when the middleware is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('unreachable'));

    $this->artisan('gezel:health')->assertExitCode(1);
});

it('fails when middleware /health returns a non-2xx', function () {
    Http::fake(['middleware.test/health' => Http::response([], 500)]);

    $this->artisan('gezel:health')->assertExitCode(1);
});

it('fails when gezel.app_id is not configured', function () {
    config()->set('gezel.app_id', null);

    Http::fake(['middleware.test/health' => Http::response(['status' => 'ok', 'docker' => true, 'containers' => ['total' => 0], 'application' => ['configured' => true]], 200)]);

    $this->artisan('gezel:health')->assertExitCode(1);
});

it('warns but succeeds when there is no provisioned owner to test against', function () {
    Http::fake(['middleware.test/health' => Http::response(['status' => 'ok', 'docker' => true, 'containers' => ['total' => 0], 'application' => ['configured' => true]], 200)]);

    $this->artisan('gezel:health')
        ->expectsOutputToContain('no provisioned owner exists')
        ->assertExitCode(0);
});

it('passes when the app_token authenticates a provisioned owner\'s container', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $owner->forceFill(['gezel_provisioned_at' => now()])->save();

    Http::fake([
        'middleware.test/health' => Http::response(['status' => 'ok', 'docker' => true, 'containers' => ['total' => 1], 'application' => ['configured' => true]], 200),
        "middleware.test/v1/containers/{$owner->gezel_id}/health" => Http::response(['uptime_seconds' => 10], 200),
    ]);

    $this->artisan('gezel:health')->assertExitCode(0);
});

it('fails and names a confused token pair when the container check 404s', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $owner->forceFill(['gezel_provisioned_at' => now()])->save();

    Http::fake([
        'middleware.test/health' => Http::response(['status' => 'ok', 'docker' => true, 'containers' => ['total' => 1], 'application' => ['configured' => true]], 200),
        "middleware.test/v1/containers/{$owner->gezel_id}/health" => Http::response([], 404),
    ]);

    $this->artisan('gezel:health')
        ->expectsOutputToContain('confused token pair')
        ->assertExitCode(1);
});
