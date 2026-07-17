<?php

namespace Onomahq\Gezel\Auth\Drivers\Passport;

use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Auth\TokenCandidate;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Onomahq\Gezel\Support\Owner;
use RuntimeException;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

final class PassportVerifier implements PrincipalVerifier
{
    public function __construct(
        private readonly ResourceServer $server,
        private readonly PrincipalGate $gate,
        private readonly PsrHttpFactory $psrHttpFactory,
    ) {}

    public function verify(string $bearer): ?GezelPrincipal
    {
        $request = SymfonyRequest::create('/', 'POST');
        $request->headers->set('Authorization', 'Bearer '.$bearer);

        try {
            $psr = $this->server->validateAuthenticatedRequest($this->psrHttpFactory->createRequest($request));
        } catch (OAuthServerException) {
            return null;
        }

        $tokenModel = Passport::tokenModel();
        $token = $tokenModel::query()->with('client')->find($psr->getAttribute('oauth_access_token_id'));

        if ($token === null) {
            return null;
        }

        if (! $this->issuedForOwnerModel($token)) {
            return null;
        }

        // Identity comes only from the token's own user_id, a real FK on the
        // oauth_access_tokens table, never a container-facing value.
        $owner = Owner::model()::query()->find($token->user_id ?? null);

        if (! $owner instanceof Model) {
            return null;
        }

        $expiresAt = $token->expires_at ?? null;

        return $this->gate->admit(new TokenCandidate(
            owner: $owner,
            principalId: (string) $token->getKey(),
            tokenName: (string) ($token->name ?? ''),
            revoked: (bool) ($token->revoked ?? false),
            expiresAt: $expiresAt?->toImmutable(),
            scopes: $token->scopes ?? [],
        ), PassportIssuer::TOKEN_NAME);
    }

    /**
     * `user_id` is only a valid FK into gezel.owner.model's table if the token
     * was issued under an auth provider mapped to that same model. Without
     * this, a token minted for an unrelated Authenticatable on the same schema
     * resolves to whatever owner row happens to share its primary key.
     *
     * Passport's own TokenGuard treats a null `client.provider` as "any
     * provider", which is safe there only because the guard is already scoped
     * to one provider and retrieves through it. This verifier has no guard, so
     * a null provider is not permission, it is the absence of the only
     * evidence that says which model `user_id` points at.
     */
    private function issuedForOwnerModel(Token $token): bool
    {
        // A deleted client is a dead token, not a misconfiguration: reject it
        // cleanly instead of throwing about a provider that cannot exist.
        if ($token->client === null) {
            return false;
        }

        // getAttribute: `provider` is a plain column with no property
        // declaration on Passport's Client model.
        $provider = $token->client->getAttribute('provider');

        if (! is_string($provider) || $provider === '') {
            throw new RuntimeException('The Passport client that issued this container bearer has no provider, so Gezel cannot prove its user_id refers to the configured gezel.owner.model.');
        }

        $providerModel = config("auth.providers.{$provider}.model");

        if (! is_string($providerModel)) {
            return false;
        }

        return ltrim($providerModel, '\\') === ltrim(Owner::model(), '\\');
    }
}
