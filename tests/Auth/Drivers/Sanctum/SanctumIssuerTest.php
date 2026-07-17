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
