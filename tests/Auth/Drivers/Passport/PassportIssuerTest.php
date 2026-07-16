<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Token;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportIssuer;
use Onomahq\Gezel\Tests\Fixtures\PassportOwner;

beforeEach(function () {
    if (! class_exists(Token::class)) {
        $this->markTestSkipped('requires laravel/passport');
    }

    migrateGezelOwnerTable(PassportOwner::class, 'passport_owners');
    migratePassportTables('gezel_passport_owners');

    config()->set('auth.guards.api', ['driver' => 'passport', 'provider' => 'gezel_passport_owners']);
    config()->set('auth.providers.gezel_passport_owners', ['driver' => 'eloquent', 'model' => PassportOwner::class]);
});

afterEach(function () {
    Schema::dropIfExists('passport_owners');
    Schema::dropIfExists('oauth_access_tokens');
    Schema::dropIfExists('oauth_refresh_tokens');
    Schema::dropIfExists('oauth_auth_codes');
    Schema::dropIfExists('oauth_clients');
    Schema::dropIfExists('oauth_device_codes');
});

it('mints a bearer named after the container token discriminator', function () {
    $owner = PassportOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = (new PassportIssuer)->issue($owner);

    expect($bearer)->toBeString()->not->toBeEmpty();

    $token = Token::query()->where('user_id', $owner->getKey())->sole();

    expect($token->name)->toBe(PassportIssuer::TOKEN_NAME);
});

it('refuses to issue for a non-Authenticatable owner', function () {
    $owner = new class extends Model {};

    (new PassportIssuer)->issue($owner);
})->throws(RuntimeException::class);
