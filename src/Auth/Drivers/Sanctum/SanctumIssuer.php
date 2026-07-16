<?php

namespace Onomahq\Gezel\Auth\Drivers\Sanctum;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use RuntimeException;

/**
 * Mints a container bearer as a Sanctum personal access token. Works on any
 * owner model using {@see HasApiTokens} — including non-User owners (Team),
 * unlike the Passport driver.
 */
final class SanctumIssuer implements ContainerBearerIssuer
{
    /**
     * The discriminator {@see SanctumVerifier} requires a candidate token to
     * carry — never trust request input or an unnamed PAT as a container
     * bearer.
     */
    public const TOKEN_NAME = 'gezel-container';

    public function __construct()
    {
        if (! trait_exists(HasApiTokens::class)) {
            throw new RuntimeException(
                "gezel.auth.driver is 'sanctum' but laravel/sanctum is not installed. Run `composer require laravel/sanctum`."
            );
        }
    }

    public function issue(Model $owner): string
    {
        if (! method_exists($owner, 'createToken')) {
            throw new RuntimeException(
                sprintf('%s must use the %s trait to issue a Gezel container bearer.', $owner::class, HasApiTokens::class)
            );
        }

        /** @var NewAccessToken $token */
        $token = $owner->createToken(self::TOKEN_NAME, ['*']);

        return $token->plainTextToken;
    }
}
