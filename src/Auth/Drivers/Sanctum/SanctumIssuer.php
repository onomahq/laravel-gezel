<?php

namespace Onomahq\Gezel\Auth\Drivers\Sanctum;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use RuntimeException;

/**
 * Mints a container bearer as a Sanctum personal access token. Works on any
 * owner model using {@see HasApiTokens}, including non-User owners (Team),
 * unlike the Passport driver.
 */
final class SanctumIssuer implements ContainerBearerIssuer
{
    /**
     * The discriminator {@see SanctumVerifier} requires a candidate token to
     * carry: never trust request input or an unnamed PAT as a container
     * bearer.
     */
    public const TOKEN_NAME = 'gezel-container';

    public function issue(Model $owner): string
    {
        if (! in_array(HasApiTokens::class, class_uses_recursive($owner), true) || ! method_exists($owner, 'createToken')) {
            throw new RuntimeException(
                sprintf('%s must use the %s trait to issue a Gezel container bearer.', $owner::class, HasApiTokens::class)
            );
        }

        /** @var NewAccessToken $token */
        $token = $owner->createToken(self::TOKEN_NAME, ['*']);

        return $token->plainTextToken;
    }

    public function activePrincipalIds(Model $owner): array
    {
        if (! method_exists($owner, 'tokens')) {
            return [];
        }

        return $owner->tokens()->where('name', self::TOKEN_NAME)->pluck('id')->all();
    }

    public function revoke(Model $owner, array $principalIds): void
    {
        if ($principalIds === [] || ! method_exists($owner, 'tokens')) {
            return;
        }

        // Sanctum tokens are deleted on revoke, not flagged, matching
        // SanctumVerifier's own assumption that a lookup hit means live.
        $owner->tokens()->whereIn('id', $principalIds)->delete();
    }
}
