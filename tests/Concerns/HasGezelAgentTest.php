<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Jobs\ProvisionContainer;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class);
});

afterEach(function () {
    Schema::dropIfExists('users');
});

it('mints a gezel_id lazily and persists it', function () {
    $user = GezelUser::create(['name' => 'Ada']);

    expect($user->gezel_id)->toBeNull();

    $id = $user->ensureGezelId();

    expect($id)->toBeString()->not->toBeEmpty();
    expect($user->fresh()->gezel_id)->toBe($id);
});

it('returns the same gezel_id on repeated calls', function () {
    $user = GezelUser::create(['name' => 'Ada']);

    expect($user->ensureGezelId())->toBe($user->ensureGezelId());
});

it('reports provisioned state from gezel_provisioned_at', function () {
    $user = GezelUser::create(['name' => 'Ada']);

    expect($user->gezelProvisioned())->toBeFalse();

    $user->forceFill(['gezel_provisioned_at' => now()])->save();

    expect($user->fresh()->gezelProvisioned())->toBeTrue();
});

it('opts into gezel by stamping gezel_opted_in_at', function () {
    Bus::fake();

    $user = GezelUser::create(['name' => 'Ada']);

    expect($user->gezelOptedIn())->toBeFalse();

    $user->optIntoGezel();

    expect($user->gezelOptedIn())->toBeTrue();
    expect($user->fresh()->gezel_opted_in_at)->not->toBeNull();
});

it('dispatches ProvisionContainer on opt-in under the opt-in strategy', function () {
    Bus::fake();
    config()->set('gezel.provisioning.strategy', 'opt-in');

    $user = GezelUser::create(['name' => 'Ada']);
    $user->optIntoGezel();

    Bus::assertDispatched(ProvisionContainer::class, fn ($job) => $job->owner->is($user));
});

it('does not dispatch on opt-in under the observer strategy', function () {
    Bus::fake();
    config()->set('gezel.provisioning.strategy', 'observer');

    GezelUser::create(['name' => 'Ada'])->optIntoGezel();

    Bus::assertNotDispatched(ProvisionContainer::class);
});

it('does not dispatch on opt-in under the manual strategy', function () {
    Bus::fake();
    config()->set('gezel.provisioning.strategy', 'manual');

    GezelUser::create(['name' => 'Ada'])->optIntoGezel();

    Bus::assertNotDispatched(ProvisionContainer::class);
});

it('does not dispatch on opt-in when provisioning is disabled', function () {
    Bus::fake();
    config()->set('gezel.provisioning.strategy', 'opt-in');
    config()->set('gezel.provisioning.enabled', false);

    GezelUser::create(['name' => 'Ada'])->optIntoGezel();

    Bus::assertNotDispatched(ProvisionContainer::class);
});
