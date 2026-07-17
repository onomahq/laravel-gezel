<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class);
    migratePersonalAccessTokensTable();
    config()->set('gezel.middleware.service_token', 'the-service-token');
});

afterEach(function () {
    Schema::dropIfExists('users');
    Schema::dropIfExists('personal_access_tokens');
});

function principalsVerifyUri(): string
{
    return '/'.trim(config('gezel.routes.prefix'), '/').'/principals/verify';
}

it('404s without the service token', function () {
    $this->postJson(principalsVerifyUri(), ['bearer' => 'whatever'])
        ->assertNotFound()
        ->assertExactJson(['error' => 'not found']);
});

it('404s a validation failure instead of returning 422', function () {
    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(principalsVerifyUri(), [])
        ->assertNotFound()
        ->assertExactJson(['error' => 'not found']);
});

it('404s when the bearer does not resolve to a principal, with the same body as a bad token', function () {
    $badToken = $this->postJson(principalsVerifyUri(), ['bearer' => 'x']);

    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(principalsVerifyUri(), ['bearer' => 'unknown-bearer'])
        ->assertNotFound()
        ->assertExactJson($badToken->json());
});

it('re-asserts expiry itself rather than answer active on a bound drivers say-so', function () {
    // A gezel.auth.driver is the app's own class and can return a principal
    // PrincipalGate never saw, so the controller cannot take `active` on trust.
    $this->app->bind(PrincipalVerifier::class, fn () => new class implements PrincipalVerifier
    {
        public function verify(string $bearer): ?GezelPrincipal
        {
            return new GezelPrincipal(
                ownerId: '1',
                gezelId: 'gezel-1',
                principalId: 'token-1',
                expiresAt: CarbonImmutable::now()->subMinute(),
                scopes: ['*'],
            );
        }
    });

    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(principalsVerifyUri(), ['bearer' => 'an-expired-one'])
        ->assertNotFound()
        ->assertExactJson(['error' => 'not found']);
});

it('answers a principal whose expiry is still in the future', function () {
    $this->app->bind(PrincipalVerifier::class, fn () => new class implements PrincipalVerifier
    {
        public function verify(string $bearer): ?GezelPrincipal
        {
            return new GezelPrincipal(
                ownerId: '1',
                gezelId: 'gezel-1',
                principalId: 'token-1',
                expiresAt: CarbonImmutable::now()->addHour(),
                scopes: ['*'],
            );
        }
    });

    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(principalsVerifyUri(), ['bearer' => 'a-live-one'])
        ->assertOk()
        ->assertJson(['user_id' => 'gezel-1', 'status' => 'active']);
});

it('answers the APP-CONTRACT §2c shape with user_id set to gezel_id, not the local PK', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $bearer = (new SanctumIssuer)->issue($owner);

    $response = $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(principalsVerifyUri(), ['bearer' => $bearer])
        ->assertOk();

    $response->assertJson([
        'user_id' => $owner->gezel_id,
        'kind' => 'gezel_container',
        'status' => 'active',
        'scopes' => ['*'],
    ]);

    expect($response->json('principal_id'))->toBeString()->not->toBe($owner->gezel_id);
    expect($response->json('user_id'))->not->toBe((string) $owner->getKey());
});
