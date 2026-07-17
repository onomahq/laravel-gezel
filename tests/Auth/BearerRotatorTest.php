<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Auth\BearerRotator;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\Tests\Fixtures\SanctumOwner;

beforeEach(function () {
    migrateGezelOwnerTable(SanctumOwner::class);
    migratePersonalAccessTokensTable();
});

afterEach(function () {
    Schema::dropIfExists('users');
    Schema::dropIfExists('personal_access_tokens');
});

it('mints, pushes, then deletes in order, and a failed push never deletes the old bearer', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $oldToken = $owner->createToken(SanctumIssuer::TOKEN_NAME, ['*']);

    $calls = [];

    try {
        (new BearerRotator(new SanctumIssuer))->rotate(
            $owner,
            function (string $bearer) use (&$calls) {
                $calls[] = 'push';

                throw new RuntimeException('middleware unreachable');
            },
            function () use (&$calls) {
                $calls[] = 'delete';
            },
        );
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('middleware unreachable');
    }

    expect($calls)->toBe(['push']);
    expect($owner->tokens()->where('id', $oldToken->accessToken->id)->exists())->toBeTrue();
});

it('deletes the old bearer only after the push succeeds', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $calls = [];

    $bearer = (new BearerRotator(new SanctumIssuer))->rotate(
        $owner,
        function (string $bearer) use (&$calls) {
            $calls[] = 'push';
        },
        function () use (&$calls) {
            $calls[] = 'delete';
        },
    );

    expect($bearer)->toBeString()->not->toBeEmpty();
    expect($calls)->toBe(['push', 'delete']);
});

it('refuses to run inside a database transaction', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $calls = [];

    DB::beginTransaction();

    try {
        expect(fn () => (new BearerRotator(new SanctumIssuer))->rotate(
            $owner,
            function () use (&$calls) {
                $calls[] = 'push';
            },
            function () use (&$calls) {
                $calls[] = 'delete';
            },
        ))->toThrow(RuntimeException::class, 'not committed yet');
    } finally {
        DB::rollBack();
    }

    // It must refuse before minting, not after: a rolled-back mint would leave
    // the middleware holding a bearer whose token row never existed.
    expect($calls)->toBe([]);
});

it('prefixes the lock key with app_id so the same owner id in another app does not collide', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    // Onoma holds the rotation lock for its own owner 1.
    config()->set('gezel.app_id', 'onoma');
    $onomaKey = "gezel:onoma:bearer-rotate:{$owner->getKey()}";
    Cache::lock($onomaKey, 30)->get();

    // Stagent's owner 1 is a different owner that happens to share a primary
    // key, so its rotation must not queue behind Onoma's lock.
    config()->set('gezel.app_id', 'stagent');

    $bearer = (new BearerRotator(new SanctumIssuer))->rotate($owner, fn () => null, fn () => null);

    expect($bearer)->toBeString()->not->toBeEmpty();

    Cache::lock($onomaKey)->forceRelease();
});

it('reconcile mints, pushes, then revokes exactly the bearers that were active before minting', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $issuer = new SanctumIssuer;
    $oldToken = $issuer->issue($owner);
    $unrelated = $owner->createToken('some-other-token');

    $calls = [];

    $bearer = (new BearerRotator($issuer))->reconcile($owner, function (string $bearer) use (&$calls) {
        $calls[] = 'push';
    });

    expect($bearer)->toBeString()->not->toBe($oldToken);
    expect($calls)->toBe(['push']);
    expect($owner->tokens()->where('name', SanctumIssuer::TOKEN_NAME)->count())->toBe(1);
    expect($owner->tokens()->where('id', $unrelated->accessToken->id)->exists())->toBeTrue();
});

it('reconcile never revokes when the push fails', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $issuer = new SanctumIssuer;
    $issuer->issue($owner);

    try {
        (new BearerRotator($issuer))->reconcile($owner, function (string $bearer): void {
            throw new RuntimeException('middleware unreachable');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('middleware unreachable');
    }

    expect($owner->tokens()->where('name', SanctumIssuer::TOKEN_NAME)->count())->toBe(2);
});

it('reconcile is a no-op revoke on an owner with no previous bearer', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = (new BearerRotator(new SanctumIssuer))->reconcile($owner, fn () => null);

    expect($bearer)->toBeString()->not->toBeEmpty();
    expect($owner->tokens()->where('name', SanctumIssuer::TOKEN_NAME)->count())->toBe(1);
});

it('accepts a custom ContainerBearerIssuer without depending on a concrete driver', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $issuer = new class implements ContainerBearerIssuer
    {
        public function issue(Model $owner): string
        {
            return 'fixed-bearer';
        }

        public function activePrincipalIds(Model $owner): array
        {
            return [];
        }

        public function revoke(Model $owner, array $principalIds): void {}
    };

    $bearer = (new BearerRotator($issuer))->rotate($owner, fn () => null, fn () => null);

    expect($bearer)->toBe('fixed-bearer');
});
