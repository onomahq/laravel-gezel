<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Onomahq\Gezel\Support\Owner;
use Onomahq\Gezel\Tests\Fixtures\GezelTeam;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

afterEach(function () {
    Schema::dropIfExists('gezel_users');
});

it('resolves the configured owner model', function () {
    migrateGezelOwnerTable(GezelUser::class, 'gezel_users');

    expect(Owner::model())->toBe(GezelUser::class);
});

it('finds an owner by gezel_id', function () {
    migrateGezelOwnerTable(GezelUser::class, 'gezel_users');

    $user = GezelUser::create(['name' => 'Ada']);
    $user->ensureGezelId();

    expect(Owner::findByGezelId($user->gezel_id)?->is($user))->toBeTrue();
});

it('returns null when no owner matches the gezel_id', function () {
    migrateGezelOwnerTable(GezelUser::class, 'gezel_users');

    expect(Owner::findByGezelId((string) Str::uuid()))->toBeNull();
});

it('allows a User-subclass owner without acknowledgement', function () {
    config()->set('gezel.owner.model', GezelUser::class);
    config()->set('gezel.owner.acknowledges_shared_memory', false);

    expect(fn () => Owner::guardSharedMemoryAcknowledgement())->not->toThrow(RuntimeException::class);
});

it('throws for a non-User owner without acknowledgement', function () {
    config()->set('gezel.owner.model', GezelTeam::class);
    config()->set('gezel.owner.acknowledges_shared_memory', false);

    expect(fn () => Owner::guardSharedMemoryAcknowledgement())->toThrow(RuntimeException::class);
});

it('allows a non-User owner once shared memory is acknowledged', function () {
    config()->set('gezel.owner.model', GezelTeam::class);
    config()->set('gezel.owner.acknowledges_shared_memory', true);

    expect(fn () => Owner::guardSharedMemoryAcknowledgement())->not->toThrow(RuntimeException::class);
});
