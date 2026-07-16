<?php

use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class, 'gezel_users');
    migratePersonalAccessTokensTable();
    config()->set('gezel.middleware.service_token', 'the-service-token');
});

afterEach(function () {
    Schema::dropIfExists('gezel_users');
    Schema::dropIfExists('personal_access_tokens');
});

function principalsVerifyUri(): string
{
    return '/'.trim(config('gezel.routes.prefix'), '/').'/principals/verify';
}

it('404s without the service token', function () {
    $this->postJson(principalsVerifyUri(), ['bearer' => 'whatever'])->assertNotFound();
});

it('404s a validation failure instead of returning 422', function () {
    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(principalsVerifyUri(), [])
        ->assertNotFound();
});

it('404s when the bearer does not resolve to a principal', function () {
    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(principalsVerifyUri(), ['bearer' => 'unknown-bearer'])
        ->assertNotFound()
        ->assertJson(['error' => 'principal not found']);
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
