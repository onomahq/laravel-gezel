<?php

use Carbon\CarbonImmutable;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Auth\PrincipalKind;
use Onomahq\Gezel\Auth\PrincipalStatus;
use Onomahq\Gezel\Auth\TokenCandidate;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

function makeCandidate(array $overrides = []): TokenCandidate
{
    $owner = new GezelUser(['id' => 1, 'gezel_id' => 'owner-gezel-id']);
    $owner->exists = true;
    $owner->setAttribute('id', 1);

    return new TokenCandidate(
        owner: $overrides['owner'] ?? $owner,
        principalId: $overrides['principalId'] ?? 'token-1',
        tokenName: $overrides['tokenName'] ?? 'gezel-container',
        expectedTokenName: $overrides['expectedTokenName'] ?? 'gezel-container',
        revoked: $overrides['revoked'] ?? false,
        expiresAt: array_key_exists('expiresAt', $overrides) ? $overrides['expiresAt'] : null,
        scopes: $overrides['scopes'] ?? ['*'],
    );
}

it('admits a candidate that clears every invariant', function () {
    $principal = (new PrincipalGate)->admit(makeCandidate());

    expect($principal)->not->toBeNull();
    expect($principal->ownerId)->toBe('1');
    expect($principal->gezelId)->toBe('owner-gezel-id');
    expect($principal->principalId)->toBe('token-1');
    expect($principal->kind)->toBe(PrincipalKind::GezelContainer);
    expect($principal->status)->toBe(PrincipalStatus::Active);
});

it('rejects a token whose name does not match the expected container token name', function () {
    $principal = (new PrincipalGate)->admit(makeCandidate([
        'tokenName' => 'some-other-token',
        'expectedTokenName' => 'gezel-container',
    ]));

    expect($principal)->toBeNull();
});

it('never trusts a driver-supplied kind — a plain PAT never admits', function () {
    // A driver that resolved *any* valid token without checking its name
    // would produce exactly this candidate; the gate is the only thing
    // standing between that and a false-positive container principal.
    $principal = (new PrincipalGate)->admit(makeCandidate([
        'tokenName' => 'api-token',
    ]));

    expect($principal)->toBeNull();
});

it('rejects a revoked candidate', function () {
    $principal = (new PrincipalGate)->admit(makeCandidate(['revoked' => true]));

    expect($principal)->toBeNull();
});

it('rejects an expired candidate', function () {
    $principal = (new PrincipalGate)->admit(makeCandidate([
        'expiresAt' => CarbonImmutable::now()->subMinute(),
    ]));

    expect($principal)->toBeNull();
});

it('admits a candidate with a future expiry', function () {
    $principal = (new PrincipalGate)->admit(makeCandidate([
        'expiresAt' => CarbonImmutable::now()->addHour(),
    ]));

    expect($principal)->not->toBeNull();
});

it('rejects an owner with no gezel_id', function () {
    $owner = new GezelUser(['id' => 2]);
    $owner->exists = true;

    $principal = (new PrincipalGate)->admit(makeCandidate(['owner' => $owner]));

    expect($principal)->toBeNull();
});

it('keeps owner_id and gezel_id as distinct fields', function () {
    $owner = new GezelUser(['id' => 42, 'gezel_id' => 'distinct-gezel-id']);
    $owner->exists = true;

    $principal = (new PrincipalGate)->admit(makeCandidate(['owner' => $owner]));

    expect($principal->ownerId)->toBe('42');
    expect($principal->gezelId)->toBe('distinct-gezel-id');
    expect($principal->ownerId)->not->toBe($principal->gezelId);
});
