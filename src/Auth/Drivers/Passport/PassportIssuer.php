<?php

namespace Onomahq\Gezel\Auth\Drivers\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\PersonalAccessTokenResult;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use RuntimeException;

/**
 * Mints a container bearer as a Passport OAuth token named 'gezel-mcp',
 * Stagent's existing convention. Passport is user-bound: the owner model
 * must be {@see Authenticatable} (documented limitation vs. the Sanctum
 * driver, which also works on Team-like owners).
 */
final class PassportIssuer implements ContainerBearerIssuer
{
    public const TOKEN_NAME = 'gezel-mcp';

    public function issue(Model $owner): string
    {
        if (! $owner instanceof Authenticatable) {
            throw new RuntimeException(
                sprintf('%s must be Authenticatable to issue a Passport container bearer because Passport is user-bound.', $owner::class)
            );
        }

        if (! in_array(HasApiTokens::class, class_uses_recursive($owner), true) || ! method_exists($owner, 'createToken')) {
            throw new RuntimeException(
                sprintf('%s must use the %s trait to issue a Gezel container bearer.', $owner::class, HasApiTokens::class)
            );
        }

        /** @var PersonalAccessTokenResult $result */
        $result = $owner->createToken(self::TOKEN_NAME);

        return $result->accessToken;
    }

    /**
     * A misconfigured owner model must fail loud here exactly like issue()
     * does. Returning an empty list instead would make a misconfigured owner
     * look "reconciled" while nothing was ever queried.
     */
    public function activePrincipalIds(Model $owner): array
    {
        if (! method_exists($owner, 'tokens')) {
            throw new RuntimeException(
                sprintf('%s must use the %s trait to manage Gezel container bearers.', $owner::class, HasApiTokens::class)
            );
        }

        return $owner->tokens()->where('name', self::TOKEN_NAME)->where('revoked', false)->pluck('id')->all();
    }

    /**
     * A misconfigured owner model must fail loud here exactly like issue()
     * does. No-oping instead would make a misconfigured owner look
     * "reconciled" while revoking nothing.
     */
    public function revoke(Model $owner, array $principalIds): void
    {
        if ($principalIds === []) {
            return;
        }

        if (! method_exists($owner, 'tokens')) {
            throw new RuntimeException(
                sprintf('%s must use the %s trait to manage Gezel container bearers.', $owner::class, HasApiTokens::class)
            );
        }

        // Flagged, not deleted, matching PassportVerifier reading `revoked`
        // off the token row rather than treating a missing row as revoked.
        $owner->tokens()->whereIn('id', $principalIds)->update(['revoked' => true]);
    }
}
