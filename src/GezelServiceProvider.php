<?php

namespace Onomahq\Gezel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\Token;
use Laravel\Sanctum\PersonalAccessToken;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportIssuer;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportVerifier;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumVerifier;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Console\Commands\GezelHealth;
use Onomahq\Gezel\Console\Commands\ProvisionMissingContainers;
use Onomahq\Gezel\Console\Commands\ReconcileContainerBearers;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Onomahq\Gezel\Contracts\StreamsGezelChat;
use Onomahq\Gezel\Jobs\ProvisionContainer;
use Onomahq\Gezel\Support\Owner;
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
            ->hasMigration('add_gezel_columns')
            ->hasCommands([
                ProvisionMissingContainers::class,
                ReconcileContainerBearers::class,
                GezelHealth::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(StreamsGezelChat::class, GezelStreamClient::class);

        $this->app->singleton(PrincipalGate::class);

        match ($driver = config('gezel.auth.driver', 'sanctum')) {
            'sanctum' => $this->bindSanctumAuth(),
            'passport' => $this->bindPassportAuth(),
            default => $this->bindCustomAuth($driver),
        };
    }

    public function packageBooted(): void
    {
        $this->registerProvisioningStrategy();
        $this->registerSelfHealing();
    }

    /**
     * The one piece of package magic (Module 6): under the 'observer'
     * strategy, every owner model row created auto-dispatches
     * ProvisionContainer. Silent otherwise, so grep this method if
     * provisioning fires and you didn't expect it. 'opt-in' dispatches from
     * HasGezelAgent::optIntoGezel() instead; 'manual' never dispatches on
     * its own.
     */
    private function registerProvisioningStrategy(): void
    {
        if (! config('gezel.provisioning.enabled', true)) {
            return;
        }

        if (config('gezel.provisioning.strategy') !== 'observer') {
            return;
        }

        Owner::model()::created(fn (Model $owner) => ProvisionContainer::dispatch($owner));
    }

    /**
     * Opt-in healing: registers hourly `gezel:provision-missing` so an owner
     * whose container never came up (a middleware blip, a lost dispatch)
     * gets repaired without manual intervention.
     */
    private function registerSelfHealing(): void
    {
        if (! config('gezel.provisioning.self_heal', false)) {
            return;
        }

        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command('gezel:provision-missing')
                ->hourly();
        });
    }

    /**
     * Binds behind closures, not an eager check, so a missing laravel/sanctum
     * install only fails the request that actually resolves one of these,
     * never every artisan command booting the framework, including the one
     * you'd reach for to fix the config.
     */
    private function bindSanctumAuth(): void
    {
        $guard = function (): void {
            if (! class_exists(PersonalAccessToken::class)) {
                throw new RuntimeException(
                    "gezel.auth.driver is 'sanctum' but laravel/sanctum is not installed. Run `composer require laravel/sanctum`."
                );
            }
        };

        $this->app->singleton(ContainerBearerIssuer::class, function ($app) use ($guard) {
            $guard();

            return $app->make(SanctumIssuer::class);
        });

        $this->app->singleton(PrincipalVerifier::class, function ($app) use ($guard) {
            $guard();

            return $app->make(SanctumVerifier::class);
        });
    }

    private function bindPassportAuth(): void
    {
        $guard = function (): void {
            if (! class_exists(Token::class)) {
                throw new RuntimeException(
                    "gezel.auth.driver is 'passport' but laravel/passport is not installed. Run `composer require laravel/passport`."
                );
            }
        };

        $this->app->singleton(ContainerBearerIssuer::class, function ($app) use ($guard) {
            $guard();

            return $app->make(PassportIssuer::class);
        });

        $this->app->singleton(PrincipalVerifier::class, function ($app) use ($guard) {
            $guard();

            return $app->make(PassportVerifier::class);
        });
    }

    /**
     * A driver value that isn't 'sanctum'/'passport' must name a class-string
     * implementing both contracts, and the same class binds to each, per
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
