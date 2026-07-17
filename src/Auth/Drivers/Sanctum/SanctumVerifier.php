<?php

namespace Onomahq\Gezel\Auth\Drivers\Sanctum;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Sanctum;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Auth\TokenCandidate;
use Onomahq\Gezel\Contracts\PrincipalVerifier;

final class SanctumVerifier implements PrincipalVerifier
{
    public function __construct(private readonly PrincipalGate $gate) {}

    public function verify(string $bearer): ?GezelPrincipal
    {
        // Sanctum::personalAccessTokenModel(), not the concrete class: an app
        // that called usePersonalAccessTokenModel() stores its PATs elsewhere.
        $tokenModel = Sanctum::personalAccessTokenModel();
        $token = $tokenModel::findToken($bearer);

        if ($token === null) {
            return null;
        }

        // Identity comes only from the token's own tokenable record, never
        // from request input.
        $owner = $token->tokenable;

        if (! $owner instanceof Model) {
            return null;
        }

        $expiresAt = $token->expires_at ?? null;

        return $this->gate->admit(new TokenCandidate(
            owner: $owner,
            principalId: (string) $token->getKey(),
            tokenName: (string) ($token->name ?? ''),
            revoked: false, // Sanctum tokens are deleted on revoke, not flagged; a lookup hit means live.
            expiresAt: $expiresAt?->toImmutable(),
            scopes: $token->abilities ?? [],
        ), SanctumIssuer::TOKEN_NAME);
    }
}
