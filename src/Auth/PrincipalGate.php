<?php

namespace Onomahq\Gezel\Auth;

/**
 * The package-enforced invariants every driver's candidate must clear before
 * it becomes a {@see GezelPrincipal}. A driver resolving identity from a
 * token's own tokenable record is not itself proof the token is the *kind* of
 * token it claims to be, still active, or unexpired — a driver could always
 * hardcode those (Stagent's shipped Passport verifier hardcodes
 * `status: 'active', scopes: ['*']` today). This class is the single place
 * that re-derives them from the token's own facts instead.
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

        $gezelId = $candidate->owner->gezel_id ?? null;

        if (! is_string($gezelId) || $gezelId === '') {
            return null;
        }

        return new GezelPrincipal(
            ownerId: (string) $candidate->owner->getKey(),
            gezelId: $gezelId,
            principalId: $candidate->principalId,
            kind: PrincipalKind::GezelContainer,
            status: PrincipalStatus::Active,
            expiresAt: $candidate->expiresAt,
            scopes: $candidate->scopes,
        );
    }
}
