<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Auth\PrincipalKind;
use Onomahq\Gezel\Auth\PrincipalStatus;
use Onomahq\Gezel\Http\RateLimitKeyResolver;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class, 'gezel_users');
});

afterEach(function () {
    Schema::dropIfExists('gezel_users');
});

it('keys on the already-resolved principal, never a body field, when one is attached', function () {
    $request = Request::create('/agent-messages', 'POST', ['user_id' => 'attacker-controlled']);
    $request->attributes->set('principal', new GezelPrincipal(
        ownerId: '1',
        gezelId: 'real-gezel-id',
        principalId: 'token-1',
        kind: PrincipalKind::GezelContainer,
        status: PrincipalStatus::Active,
        expiresAt: null,
        scopes: ['*'],
    ));

    expect((new RateLimitKeyResolver)->resolve($request))->toBe('real-gezel-id');
});

it('keys on a body user_id only once it resolves to a real owner', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $request = Request::create('/turn-context', 'POST', ['user_id' => $owner->gezel_id]);

    expect((new RateLimitKeyResolver)->resolve($request))->toBe($owner->gezel_id);
});

it('collapses an unresolvable body user_id into one shared bucket', function () {
    $request = Request::create('/turn-context', 'POST', ['user_id' => 'not-a-real-owner']);

    expect((new RateLimitKeyResolver)->resolve($request))->toBe('unresolved');

    $request2 = Request::create('/turn-context', 'POST', ['user_id' => 'also-not-a-real-owner']);

    expect((new RateLimitKeyResolver)->resolve($request2))->toBe('unresolved');
});

it('collapses a missing user_id into the shared bucket too', function () {
    $request = Request::create('/principals/verify', 'POST', ['bearer' => 'some-bearer']);

    expect((new RateLimitKeyResolver)->resolve($request))->toBe('unresolved');
});
