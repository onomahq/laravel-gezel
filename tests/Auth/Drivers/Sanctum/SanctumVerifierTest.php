<?php

use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumVerifier;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Auth\PrincipalKind;
use Onomahq\Gezel\Tests\Fixtures\SanctumOwner;

beforeEach(function () {
    if (! class_exists(PersonalAccessToken::class)) {
        $this->markTestSkipped('requires laravel/sanctum');
    }

    migrateGezelOwnerTable(SanctumOwner::class, 'sanctum_owners');
    migratePersonalAccessTokensTable();
});

afterEach(function () {
    Schema::dropIfExists('sanctum_owners');
    Schema::dropIfExists('personal_access_tokens');
});

it('verifies a bearer minted by SanctumIssuer into a GezelPrincipal', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = (new SanctumIssuer)->issue($owner);

    $principal = (new SanctumVerifier(new PrincipalGate))->verify($bearer);

    expect($principal)->not->toBeNull();
    expect($principal->ownerId)->toBe((string) $owner->getKey());
    expect($principal->gezelId)->toBe($owner->gezel_id);
    expect($principal->kind)->toBe(PrincipalKind::GezelContainer);
});

it('rejects an ordinary PAT that was never named as a container bearer', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = $owner->createToken('some-other-api-token', ['*'])->plainTextToken;

    $principal = (new SanctumVerifier(new PrincipalGate))->verify($bearer);

    expect($principal)->toBeNull();
});

it('rejects an unknown bearer', function () {
    $principal = (new SanctumVerifier(new PrincipalGate))->verify('unknown-bearer-string');

    expect($principal)->toBeNull();
});

it('rejects an expired container bearer', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $token = $owner->createToken(SanctumIssuer::TOKEN_NAME, ['*']);
    $token->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();

    $principal = (new SanctumVerifier(new PrincipalGate))->verify($token->plainTextToken);

    expect($principal)->toBeNull();
});
