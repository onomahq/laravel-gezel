<?php

use Illuminate\Support\Facades\Schema;
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
    $user = GezelUser::create(['name' => 'Ada']);

    expect($user->gezelOptedIn())->toBeFalse();

    $user->optIntoGezel();

    expect($user->gezelOptedIn())->toBeTrue();
    expect($user->fresh()->gezel_opted_in_at)->not->toBeNull();
});
