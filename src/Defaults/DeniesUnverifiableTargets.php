<?php

namespace Onomahq\Gezel\Defaults;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Onomahq\Gezel\Contracts\TargetOwnershipVerifier;

/**
 * Ships as the default {@see TargetOwnershipVerifier}. A payload naming no
 * target has nothing to scope, so it passes: the owner is already proven by
 * the container principal, which is the whole of what a default install (the
 * shipped handler just fires an event with the payload) needs.
 *
 * A payload that does name a target is refused, because the package cannot
 * prove that chat belongs to that owner without knowing the app's schema, and
 * answering "delivered" for a target it never checked is exactly the promise
 * Module 4 must not make. Binding a real verifier is the way to accept those.
 */
final class DeniesUnverifiableTargets implements TargetOwnershipVerifier
{
    public function verify(Model $owner, array $payload): bool
    {
        $targets = array_keys(array_intersect_key($payload, array_flip(self::TARGET_KEYS)));

        if ($targets === []) {
            return true;
        }

        // A bare 404 for this would be indistinguishable from a bad token and
        // cost someone an afternoon, so say once, loudly, what to bind.
        Log::warning('Gezel refused an agent message naming a target it cannot prove belongs to the owner; bind a '.TargetOwnershipVerifier::class.' to accept targeted messages.', [
            'owner_model' => $owner::class,
            'owner_id' => $owner->getKey(),
            'targets' => $targets,
        ]);

        return false;
    }
}
