<?php

namespace Onomahq\Gezel;

use Onomahq\Gezel\Auth\Drivers\Passport\PassportIssuer;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportVerifier;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumVerifier;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Onomahq\Gezel\Support\Owner;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GezelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-gezel')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('add_gezel_columns');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PrincipalGate::class);

        match (config('gezel.auth.driver', 'sanctum')) {
            'sanctum' => $this->bindSanctumAuth(),
            'passport' => $this->bindPassportAuth(),
            // Any other value names an app-supplied binding class-string;
            // the app registers ContainerBearerIssuer/PrincipalVerifier
            // itself and this provider leaves both untouched.
            default => null,
        };
    }

    public function packageBooted(): void
    {
        Owner::guardSharedMemoryAcknowledgement();
    }

    private function bindSanctumAuth(): void
    {
        $this->app->singleton(ContainerBearerIssuer::class, SanctumIssuer::class);
        $this->app->singleton(PrincipalVerifier::class, SanctumVerifier::class);
    }

    private function bindPassportAuth(): void
    {
        $this->app->singleton(ContainerBearerIssuer::class, PassportIssuer::class);
        $this->app->singleton(PrincipalVerifier::class, PassportVerifier::class);
    }
}
