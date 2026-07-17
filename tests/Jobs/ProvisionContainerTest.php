<?php

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\GezelOrchestrator;
use Onomahq\Gezel\Jobs\ProvisionContainer;
use Onomahq\Gezel\Tests\Fixtures\SanctumOwner;

beforeEach(function () {
    migrateGezelOwnerTable(SanctumOwner::class);
    migratePersonalAccessTokensTable();

    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token-123');
});

afterEach(function () {
    Schema::dropIfExists('users');
    Schema::dropIfExists('personal_access_tokens');
});

it('mints a bearer, provisions, and stamps gezel_provisioned_at', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);

    Http::fake([
        'middleware.test/v1/containers/*/provision' => Http::response([
            'container_id' => 'c-abc',
            'status' => 'provisioned',
        ], 200),
    ]);

    ProvisionContainer::dispatchSync($owner);

    expect($owner->fresh()->gezelProvisioned())->toBeTrue();
    expect($owner->fresh()->gezel_id)->not->toBeNull();

    Http::assertSent(function ($request) use ($owner) {
        return $request->url() === "http://middleware.test/v1/containers/{$owner->fresh()->gezel_id}/provision"
            && $request->hasHeader('Authorization', 'Bearer app-token-123')
            && filled($request['container_token']);
    });
});

it('no-ops when the owner is already provisioned', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $owner->forceFill(['gezel_provisioned_at' => now()])->save();

    Http::fake();

    ProvisionContainer::dispatchSync($owner);

    Http::assertNothingSent();
});

it('swallows ContainerLifecycleDisabledException without stamping provisioned_at', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);

    Http::fake([
        'middleware.test/v1/containers/*/provision' => Http::response('Docker not running', 501),
    ]);

    ProvisionContainer::dispatchSync($owner);

    expect($owner->fresh()->gezelProvisioned())->toBeFalse();
});

it('revokes the bearer it just minted when the middleware refuses container lifecycle', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);

    Http::fake([
        'middleware.test/v1/containers/*/provision' => Http::response('Docker not running', 501),
    ]);

    ProvisionContainer::dispatchSync($owner);

    expect($owner->tokens()->count())->toBe(0);
});

it('revokes the bearer it just minted and rethrows on any other provision failure', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);

    Http::fake([
        'middleware.test/v1/containers/*/provision' => Http::response('boom', 500),
    ]);

    expect(fn () => (new ProvisionContainer($owner))->handle(
        app(ContainerBearerIssuer::class),
        app(GezelOrchestrator::class),
    ))->toThrow(RequestException::class);

    expect($owner->tokens()->count())->toBe(0);
});

it('does not leave a live bearer behind across repeated failed attempts', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);

    Http::fake([
        'middleware.test/v1/containers/*/provision' => Http::response('boom', 500),
    ]);

    foreach (range(1, 3) as $attempt) {
        try {
            (new ProvisionContainer($owner))->handle(
                app(ContainerBearerIssuer::class),
                app(GezelOrchestrator::class),
            );
        } catch (RequestException) {
            // expected on every attempt
        }
    }

    expect($owner->tokens()->count())->toBe(0);
});

it('never revokes a bearer that already existed before this attempt minted its own', function () {
    // Reproduces: provision() succeeds but the job dies before forceFill/save
    // commits, so gezel_provisioned_at stays null and a retry mints a second
    // bearer against a container that already exists (AlreadyExists). Only
    // the second attempt's own bearer may be revoked; the first is the one
    // the live container actually holds.
    $owner = SanctumOwner::create(['name' => 'Ada']);

    $issuer = app(ContainerBearerIssuer::class);
    $preExistingBearer = $issuer->issue($owner);

    Http::fake([
        'middleware.test/v1/containers/*/provision' => Http::response('boom', 409),
    ]);

    try {
        (new ProvisionContainer($owner))->handle($issuer, app(GezelOrchestrator::class));
    } catch (RequestException) {
        // expected
    }

    expect($owner->tokens()->count())->toBe(1);
    expect(PersonalAccessToken::findToken($preExistingBearer))->not->toBeNull();
});

it('defers dispatch until after the enclosing transaction commits', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);

    expect((new ProvisionContainer($owner))->afterCommit)->toBeTrue();
});

it('is unique per owner, prefixed with app_id, and locks via the configured lock store', function () {
    config()->set('gezel.app_id', 'onoma');

    $owner = SanctumOwner::create(['name' => 'Ada']);

    $job = new ProvisionContainer($owner);

    expect($job->uniqueId())->toBe("gezel:onoma:provision-container:{$owner->getKey()}");
    expect($job->uniqueVia())->toBeInstanceOf(Repository::class);
    expect($job->uniqueFor())->toBeGreaterThan(0);
});

it('does not collide with another app\'s owner sharing the same primary key', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);

    config()->set('gezel.app_id', 'onoma');
    $onomaId = (new ProvisionContainer($owner))->uniqueId();

    config()->set('gezel.app_id', 'stagent');
    $stagentId = (new ProvisionContainer($owner))->uniqueId();

    expect($onomaId)->not->toBe($stagentId);
});

it('never carries the minted bearer in its serialized payload', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);

    $job = new ProvisionContainer($owner);

    $serialized = serialize($job);

    // The job holds only the owner (SerializesModels stores class + key);
    // the bearer is minted inside handle(), so no plaintext token can appear
    // here regardless of what gets issued at runtime.
    expect($serialized)->not->toContain('plainTextToken');
    expect($serialized)->not->toMatch('/\d+\|[A-Za-z0-9]{40}/');
});
