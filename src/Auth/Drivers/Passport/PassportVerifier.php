<?php

namespace Onomahq\Gezel\Auth\Drivers\Passport;

use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\Token;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Auth\TokenCandidate;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Onomahq\Gezel\Support\Owner;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

final class PassportVerifier implements PrincipalVerifier
{
    public function __construct(
        private readonly ResourceServer $server,
        private readonly PrincipalGate $gate,
    ) {}

    public function verify(string $bearer): ?GezelPrincipal
    {
        $request = SymfonyRequest::create('/', 'POST');
        $request->headers->set('Authorization', 'Bearer '.$bearer);

        try {
            $psr = $this->server->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            return null;
        }

        $token = Token::query()->find($psr->getAttribute('oauth_access_token_id'));

        if (! $token instanceof Token) {
            return null;
        }

        // Identity comes only from the token's own user_id — a real FK on
        // the oauth_access_tokens table, never a container-facing value.
        $owner = Owner::model()::query()->find($token->user_id ?? null);

        if (! $owner instanceof Model) {
            return null;
        }

        $expiresAt = $token->expires_at ?? null;

        return $this->gate->admit(new TokenCandidate(
            owner: $owner,
            principalId: (string) $token->getKey(),
            tokenName: (string) ($token->name ?? ''),
            expectedTokenName: PassportIssuer::TOKEN_NAME,
            revoked: (bool) ($token->revoked ?? false),
            expiresAt: $expiresAt?->toImmutable(),
            scopes: $token->scopes ?? [],
        ));
    }
}
