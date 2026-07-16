<?php

namespace Onomahq\Gezel\Auth\Drivers\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\PersonalAccessTokenResult;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use RuntimeException;

/**
 * Mints a container bearer as a Passport OAuth token named 'gezel-mcp' —
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
                sprintf('%s must be Authenticatable to issue a Passport container bearer — Passport is user-bound.', $owner::class)
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
}
