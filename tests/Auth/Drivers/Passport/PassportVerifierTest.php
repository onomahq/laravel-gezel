<?php

use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use League\OAuth2\Server\ResourceServer;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportIssuer;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportVerifier;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;
use Onomahq\Gezel\Tests\Fixtures\PassportOwner;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

beforeEach(function () {
    if (! class_exists(Token::class)) {
        $this->markTestSkipped('requires laravel/passport');
    }

    migrateGezelOwnerTable(PassportOwner::class);
    migratePassportTables('gezel_passport_owners');

    config()->set('auth.guards.api', ['driver' => 'passport', 'provider' => 'gezel_passport_owners']);
    config()->set('auth.providers.gezel_passport_owners', ['driver' => 'eloquent', 'model' => PassportOwner::class]);
});

afterEach(function () {
    Schema::dropIfExists('users');
    Schema::dropIfExists('oauth_access_tokens');
    Schema::dropIfExists('oauth_refresh_tokens');
    Schema::dropIfExists('oauth_auth_codes');
    Schema::dropIfExists('oauth_clients');
    Schema::dropIfExists('oauth_device_codes');
});

function passportVerifier(): PassportVerifier
{
    return new PassportVerifier(app(ResourceServer::class), new PrincipalGate, app(PsrHttpFactory::class));
}

it('verifies a bearer minted by PassportIssuer into a GezelPrincipal', function () {
    $owner = PassportOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = (new PassportIssuer)->issue($owner);

    $principal = passportVerifier()->verify($bearer);

    expect($principal)->not->toBeNull();
    expect($principal->ownerId)->toBe((string) $owner->getKey());
    expect($principal->gezelId)->toBe($owner->gezel_id);
    expect($principal->kind)->toBe('gezel_container');
});

it('rejects a Passport token that was never named as a container bearer', function () {
    $owner = PassportOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = $owner->createToken('some-other-oauth-token')->accessToken;

    $principal = passportVerifier()->verify($bearer);

    expect($principal)->toBeNull();
});

it('rejects a revoked container bearer', function () {
    $owner = PassportOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = (new PassportIssuer)->issue($owner);

    Token::query()->where('user_id', $owner->getKey())->update(['revoked' => true]);

    $principal = passportVerifier()->verify($bearer);

    expect($principal)->toBeNull();
});

it('rejects a bearer whose auth provider does not map to the configured owner model', function () {
    // The gap a prior review found: user_id is only a valid FK into
    // gezel.owner.model's table if the token was actually issued under a
    // provider mapped to that model. Mint under PassportOwner's provider,
    // then point gezel.owner.model at an unrelated Authenticatable, so the
    // token's user_id would otherwise resolve to whatever row of the new
    // model happens to share that primary key.
    $owner = PassportOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = (new PassportIssuer)->issue($owner);

    config()->set('gezel.owner.model', GezelUser::class);

    $principal = passportVerifier()->verify($bearer);

    expect($principal)->toBeNull();
});

it('rejects an unknown bearer', function () {
    $principal = passportVerifier()->verify('not-a-real-jwt');

    expect($principal)->toBeNull();
});

it('refuses a bearer from a client with no provider rather than guessing the owner model', function () {
    // oauth_clients.provider is nullable. Passport's own TokenGuard reads null
    // as "any provider", which is safe there because the guard is already
    // scoped to one. Here it would mean resolving user_id against whatever
    // gezel.owner.model happens to be, so it fails loudly instead.
    $owner = PassportOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = (new PassportIssuer)->issue($owner);

    Client::query()->update(['provider' => null]);

    expect(fn () => passportVerifier()->verify($bearer))
        ->toThrow(RuntimeException::class, 'has no provider');
});
