<?php

namespace Onomahq\Gezel\Auth\Drivers\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\PersonalAccessTokenResult;
use Laravel\Passport\Token;
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

    public function __construct()
    {
        if (! class_exists(Token::class)) {
            throw new RuntimeException(
                "gezel.auth.driver is 'passport' but laravel/passport is not installed. Run `composer require laravel/passport`."
            );
        }
    }

    public function issue(Model $owner): string
    {
        if (! $owner instanceof Authenticatable) {
            throw new RuntimeException(
                sprintf('%s must be Authenticatable to issue a Passport container bearer — Passport is user-bound.', $owner::class)
            );
        }

        if (! method_exists($owner, 'createToken')) {
            throw new RuntimeException(
                sprintf('%s must use the Laravel\\Passport\\HasApiTokens trait to issue a Gezel container bearer.', $owner::class)
            );
        }

        /** @var PersonalAccessTokenResult $result */
        $result = $owner->createToken(self::TOKEN_NAME);

        return $result->accessToken;
    }
}
