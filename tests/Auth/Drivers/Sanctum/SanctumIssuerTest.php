<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Tests\Fixtures\SanctumOwner;

beforeEach(function () {
    if (! class_exists(PersonalAccessToken::class)) {
        $this->markTestSkipped('requires laravel/sanctum');
    }

    migrateGezelOwnerTable(SanctumOwner::class);
    migratePersonalAccessTokensTable();
});

afterEach(function () {
    Schema::dropIfExists('users');
    Schema::dropIfExists('personal_access_tokens');
});

it('mints a bearer named after the container token discriminator', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = (new SanctumIssuer)->issue($owner);

    expect($bearer)->toBeString()->not->toBeEmpty();

    $token = PersonalAccessToken::findToken($bearer);

    expect($token)->not->toBeNull();
    expect($token->name)->toBe(SanctumIssuer::TOKEN_NAME);
    expect($token->tokenable->is($owner))->toBeTrue();
});

it('refuses to issue for an owner without HasApiTokens', function () {
    $owner = new class extends Model {};

    (new SanctumIssuer)->issue($owner);
})->throws(RuntimeException::class);

it('reports the active container-bearer token ids and revokes exactly those', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $issuer = new SanctumIssuer;
    $issuer->issue($owner);
    $unrelated = $owner->createToken('some-other-token');

    $ids = $issuer->activePrincipalIds($owner);

    expect($ids)->toHaveCount(1);
    expect($ids)->not->toContain($unrelated->accessToken->id);

    $issuer->revoke($owner, $ids);

    expect($owner->tokens()->where('name', SanctumIssuer::TOKEN_NAME)->exists())->toBeFalse();
    expect($owner->tokens()->where('id', $unrelated->accessToken->id)->exists())->toBeTrue();
});

it('does nothing when revoking an empty list of ids', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $token = (new SanctumIssuer)->issue($owner);

    (new SanctumIssuer)->revoke($owner, []);

    expect(PersonalAccessToken::findToken($token))->not->toBeNull();
});

// An app that already mints container bearers under its own label cannot adopt
// this driver otherwise: every live bearer would fail verification until a
// fleet-wide rotation. The package has no business dictating a string that
// already exists in someone's database.
it('mints under a configured token name', function () {
    config()->set('gezel.auth.container_token_name', 'onoma-container-bearer');
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    (new SanctumIssuer)->issue($owner);

    expect($owner->tokens()->first()->name)->toBe('onoma-container-bearer');
});

it('falls back to the default when the configured name is blank', function () {
    config()->set('gezel.auth.container_token_name', '');

    expect(SanctumIssuer::tokenName())->toBe(SanctumIssuer::TOKEN_NAME);
});

it('finds and revokes bearers under the configured name', function () {
    config()->set('gezel.auth.container_token_name', 'onoma-container-bearer');
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $issuer = new SanctumIssuer;
    $issuer->issue($owner);

    $ids = $issuer->activePrincipalIds($owner);
    expect($ids)->toHaveCount(1);

    $issuer->revoke($owner, $ids);
    expect($owner->tokens()->count())->toBe(0);
});
