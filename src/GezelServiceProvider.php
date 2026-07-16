<?php

namespace Onomahq\Gezel;

use Laravel\Passport\Token;
use Laravel\Sanctum\PersonalAccessToken;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportIssuer;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportVerifier;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumVerifier;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GezelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-gezel')
            ->hasConfigFile()
            ->hasMigration('add_gezel_columns');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PrincipalGate::class);

        match ($driver = config('gezel.auth.driver', 'sanctum')) {
            'sanctum' => $this->bindSanctumAuth(),
            'passport' => $this->bindPassportAuth(),
            default => $this->bindCustomAuth($driver),
        };
    }

    private function bindSanctumAuth(): void
    {
        if (! class_exists(PersonalAccessToken::class)) {
            throw new RuntimeException(
                "gezel.auth.driver is 'sanctum' but laravel/sanctum is not installed. Run `composer require laravel/sanctum`."
            );
        }

        $this->app->singleton(ContainerBearerIssuer::class, SanctumIssuer::class);
        $this->app->singleton(PrincipalVerifier::class, SanctumVerifier::class);
    }

    private function bindPassportAuth(): void
    {
        if (! class_exists(Token::class)) {
            throw new RuntimeException(
                "gezel.auth.driver is 'passport' but laravel/passport is not installed. Run `composer require laravel/passport`."
            );
        }

        $this->app->singleton(ContainerBearerIssuer::class, PassportIssuer::class);
        $this->app->singleton(PrincipalVerifier::class, PassportVerifier::class);
    }

    /**
     * A driver value that isn't 'sanctum'/'passport' must name a class-string
     * implementing both contracts — the same class binds to each, per
     * config/gezel.php's own comment. Validated here so a typo ("sanctumm")
     * fails loud with the actual bad value, not three layers downstream as
     * "Target [ContainerBearerIssuer] is not instantiable."
     */
    private function bindCustomAuth(mixed $driver): void
    {
        if (! is_string($driver) || ! is_a($driver, ContainerBearerIssuer::class, true) || ! is_a($driver, PrincipalVerifier::class, true)) {
            throw new RuntimeException(
                'gezel.auth.driver ['.var_export($driver, true)."] must be 'sanctum', 'passport', or a class-string implementing both ContainerBearerIssuer and PrincipalVerifier."
            );
        }

        $this->app->singleton(ContainerBearerIssuer::class, $driver);
        $this->app->singleton(PrincipalVerifier::class, $driver);
    }
}
