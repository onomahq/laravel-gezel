<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Onomahq\Gezel\Support\Owner;
use Onomahq\Gezel\Tests\Fixtures\GezelTeam;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

afterEach(function () {
    Schema::dropIfExists('users');
});

it('resolves the configured owner model', function () {
    migrateGezelOwnerTable(GezelUser::class);

    expect(Owner::model())->toBe(GezelUser::class);
});

it('finds an owner by gezel_id', function () {
    migrateGezelOwnerTable(GezelUser::class);

    $user = GezelUser::create(['name' => 'Ada']);
    $user->ensureGezelId();

    expect(Owner::findByGezelId($user->gezel_id)?->is($user))->toBeTrue();
});

it('returns null when no owner matches the gezel_id', function () {
    migrateGezelOwnerTable(GezelUser::class);

    expect(Owner::findByGezelId((string) Str::uuid()))->toBeNull();
});

it('resolves an authenticatable owner without acknowledgement', function () {
    config()->set('gezel.owner.model', GezelUser::class);
    config()->set('gezel.owner.acknowledges_shared_memory', false);

    expect(Owner::model())->toBe(GezelUser::class);
});

it('refuses a non-authenticatable owner that has not acknowledged shared memory', function () {
    config()->set('gezel.owner.model', GezelTeam::class);
    config()->set('gezel.owner.acknowledges_shared_memory', false);

    expect(fn () => Owner::model())->toThrow(RuntimeException::class, 'acknowledges_shared_memory');
});

it('resolves a non-authenticatable owner once shared memory is acknowledged', function () {
    config()->set('gezel.owner.model', GezelTeam::class);
    config()->set('gezel.owner.acknowledges_shared_memory', true);

    expect(Owner::model())->toBe(GezelTeam::class);
});

it('reports a missing owner model class distinctly from the shared memory guard', function () {
    config()->set('gezel.owner.model', 'App\Models\NotHere');

    expect(fn () => Owner::model())->toThrow(RuntimeException::class, 'does not exist');
});

it('refuses an owner model that does not implement GezelOwner', function () {
    config()->set('gezel.owner.model', OwnerTestModelWithoutGezelOwner::class);

    expect(fn () => Owner::model())->toThrow(RuntimeException::class, 'GezelOwner');
});

class OwnerTestModelWithoutGezelOwner extends Model
{
    protected $table = 'users';
}
