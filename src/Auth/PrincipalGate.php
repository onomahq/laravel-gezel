<?php

namespace Onomahq\Gezel\Auth;

use Onomahq\Gezel\Support\Owner;

/**
 * The package-enforced invariants every driver's candidate must clear before
 * it becomes a {@see GezelPrincipal}. Revocation and expiry are re-derived
 * from the token's own facts rather than trusted as a driver-reported label
 * (Stagent's shipped Passport verifier hardcodes `status: 'active'` without
 * checking `revoked` at all). The owner-model check below is the other half:
 * a token resolved from its own tokenable record still isn't proof that
 * tokenable is an instance of the app's configured owner model — a Sanctum
 * PAT belonging to some other Authenticatable on the same schema would
 * otherwise verify clean.
 */
final class PrincipalGate
{
    public function admit(TokenCandidate $candidate): ?GezelPrincipal
    {
        if ($candidate->tokenName !== $candidate->expectedTokenName) {
            return null;
        }

        if ($candidate->revoked) {
            return null;
        }

        if ($candidate->expiresAt !== null && $candidate->expiresAt->isPast()) {
            return null;
        }

        $ownerModel = Owner::model();

        if (! $candidate->owner instanceof $ownerModel) {
            return null;
        }

        $gezelId = $candidate->owner->gezel_id ?? null;

        if (! is_string($gezelId) || $gezelId === '') {
            return null;
        }

        return new GezelPrincipal(
            ownerId: (string) $candidate->owner->getKey(),
            gezelId: $gezelId,
            principalId: $candidate->principalId,
            kind: 'gezel_container',
            status: 'active',
            expiresAt: $candidate->expiresAt,
            scopes: $candidate->scopes,
        );
    }
}
