<?php

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

it('defers dispatch until after the enclosing transaction commits', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);

    expect((new ProvisionContainer($owner))->afterCommit)->toBeTrue();
});

it('is unique per owner and locks via the configured lock store', function () {
    $owner = SanctumOwner::create(['name' => 'Ada']);

    $job = new ProvisionContainer($owner);

    expect($job->uniqueId())->toBe((string) $owner->getKey());
    expect($job->uniqueVia())->toBeInstanceOf(Repository::class);
    expect($job->uniqueFor())->toBeGreaterThan(0);
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
