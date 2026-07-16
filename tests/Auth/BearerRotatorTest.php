<?php

use Illuminate\Database\Eloquent\Model;
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

it('mints, pushes, then deletes in order — a failed push never deletes the old bearer', function () {
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

it('accepts a custom ContainerBearerIssuer without depending on a concrete driver', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $issuer = new class implements ContainerBearerIssuer
    {
        public function issue(Model $owner): string
        {
            return 'fixed-bearer';
        }
    };

    $bearer = (new BearerRotator($issuer))->rotate($owner, fn () => null, fn () => null);

    expect($bearer)->toBe('fixed-bearer');
});
