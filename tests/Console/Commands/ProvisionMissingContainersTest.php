<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Tests\Fixtures\SanctumOwner;

beforeEach(function () {
    migrateGezelOwnerTable(SanctumOwner::class);
    migratePersonalAccessTokensTable();

    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token-123');
});

function fakeProvisionSucceeds(): void
{
    Http::fake([
        'middleware.test/v1/containers/*/provision' => Http::response([
            'container_id' => 'c-abc',
            'status' => 'provisioned',
        ], 200),
    ]);
}

afterEach(function () {
    Schema::dropIfExists('users');
    Schema::dropIfExists('personal_access_tokens');
});

it('provisions every owner lacking a container under a non-opt-in strategy', function () {
    fakeProvisionSucceeds();
    config()->set('gezel.provisioning.strategy', 'manual');

    $unprovisioned = SanctumOwner::create(['name' => 'Ada']);
    $provisioned = SanctumOwner::create(['name' => 'Grace']);
    $provisioned->ensureGezelId();
    $provisioned->forceFill(['gezel_provisioned_at' => now()])->save();

    $this->artisan('gezel:provision-missing')->assertExitCode(0);

    expect($unprovisioned->fresh()->gezelProvisioned())->toBeTrue();
    Http::assertSentCount(1);
});

it('only considers opted-in owners under the opt-in strategy', function () {
    fakeProvisionSucceeds();
    config()->set('gezel.provisioning.strategy', 'opt-in');

    $optedIn = SanctumOwner::create(['name' => 'Ada']);
    $optedIn->forceFill(['gezel_opted_in_at' => now()])->save();
    $notOptedIn = SanctumOwner::create(['name' => 'Grace']);

    $this->artisan('gezel:provision-missing')->assertExitCode(0);

    expect($optedIn->fresh()->gezelProvisioned())->toBeTrue();
    expect($notOptedIn->fresh()->gezelProvisioned())->toBeFalse();
    Http::assertSentCount(1);
});

it('does nothing when every eligible owner already has a container', function () {
    Http::fake();
    config()->set('gezel.provisioning.strategy', 'manual');

    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $owner->forceFill(['gezel_provisioned_at' => now()])->save();

    $this->artisan('gezel:provision-missing')
        ->expectsOutputToContain('No owners need provisioning.')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('re-provisions already-provisioned owners with --force', function () {
    fakeProvisionSucceeds();
    config()->set('gezel.provisioning.strategy', 'manual');

    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $owner->forceFill(['gezel_provisioned_at' => now()])->save();

    $this->artisan('gezel:provision-missing', ['--force' => true])->assertExitCode(0);

    Http::assertSentCount(1);
    expect($owner->fresh()->gezelProvisioned())->toBeTrue();
});

it('reports failure without aborting the remaining owners', function () {
    config()->set('gezel.provisioning.strategy', 'manual');

    Http::fake([
        'middleware.test/v1/containers/*/provision' => Http::response('boom', 500),
    ]);

    SanctumOwner::create(['name' => 'Ada']);
    SanctumOwner::create(['name' => 'Grace']);

    $this->artisan('gezel:provision-missing')->assertExitCode(1);

    Http::assertSentCount(2);
});
