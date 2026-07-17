<?php

use Illuminate\Support\Facades\Log;
use Onomahq\Gezel\Contracts\TargetOwnershipVerifier;
use Onomahq\Gezel\Defaults\DeniesUnverifiableTargets;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

function owner(): GezelUser
{
    $owner = new GezelUser(['id' => 1, 'gezel_id' => 'gezel-1']);
    $owner->exists = true;

    return $owner;
}

it('passes a payload naming no target, which is all a default install sends', function () {
    expect((new DeniesUnverifiableTargets)->verify(owner(), ['message' => 'hi']))->toBeTrue();
});

it('refuses every key that names a target', function (string $key) {
    Log::spy();

    expect((new DeniesUnverifiableTargets)->verify(owner(), ['message' => 'hi', $key => 'x']))->toBeFalse();
})->with(TargetOwnershipVerifier::TARGET_KEYS);

it('says once, loudly, what to bind, so a refusal is not an afternoon of guessing', function () {
    Log::spy();

    (new DeniesUnverifiableTargets)->verify(owner(), ['message' => 'hi', 'chat_id' => 'c-1']);

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
        return str_contains($message, TargetOwnershipVerifier::class)
            && $context['targets'] === ['chat_id']
            && $context['owner_id'] === 1;
    });
});
