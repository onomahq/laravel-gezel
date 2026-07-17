<?php

namespace Onomahq\Gezel;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Token;
use Laravel\Sanctum\PersonalAccessToken;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportIssuer;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportVerifier;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumVerifier;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Contracts\AgentMessageHandler;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\Contracts\OwnerMembershipVerifier;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Onomahq\Gezel\Contracts\StreamsGezelChat;
use Onomahq\Gezel\Contracts\TargetOwnershipVerifier;
use Onomahq\Gezel\Contracts\TurnContextProvider;
use Onomahq\Gezel\Defaults\AlwaysAllowMembershipVerifier;
use Onomahq\Gezel\Defaults\DeniesUnverifiableTargets;
use Onomahq\Gezel\Defaults\FiresGezelAgentMessageReceived;
use Onomahq\Gezel\Defaults\NullTurnContextProvider;
use Onomahq\Gezel\Http\GezelRefusal;
use Onomahq\Gezel\Http\RateLimitKeyResolver;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GezelServiceProvider extends PackageServiceProvider
{
    /** Stagent's shipped numbers, per Module 4. */
    public const IP_LIMIT_PER_MINUTE = 600;

    public const PRINCIPAL_LIMIT_PER_MINUTE = 120;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-gezel')
            ->hasConfigFile()
            ->hasRoute('gezel')
            ->hasMigration('add_gezel_columns');
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

        // bindIf so an app that already registered its own implementation,
        // e.g. Onoma broadcasting AgentMessage over Reverb, keeps it.
        $this->app->bindIf(AgentMessageHandler::class, FiresGezelAgentMessageReceived::class);
        $this->app->bindIf(TurnContextProvider::class, NullTurnContextProvider::class);
        $this->app->bindIf(OwnerMembershipVerifier::class, AlwaysAllowMembershipVerifier::class);
        $this->app->bindIf(TargetOwnershipVerifier::class, DeniesUnverifiableTargets::class);
    }

    public function packageBooted(): void
    {
        // The Gezel middleware relays every callback from one IP, so the
        // shared 'api' limiter would cap all internal traffic together.
        // Generous per-principal limit, plus an IP ceiling against probing.
        RateLimiter::for('gezel-internal', function (Request $request) {
            $key = app(RateLimitKeyResolver::class)->resolve($request);

            return [
                Limit::perMinute(self::IP_LIMIT_PER_MINUTE)->by('gezel-ip:'.$request->ip()),
                Limit::perMinute(self::PRINCIPAL_LIMIT_PER_MINUTE)->by('gezel-principal:'.$key),
            ];
        });

        // The IP ceiling only. principals/verify resolves the principal, so it
        // has none to key on: a per-principal limit there would put every
        // container's verification in one bucket and cap them collectively.
        RateLimiter::for('gezel-verify', function (Request $request) {
            return Limit::perMinute(self::IP_LIMIT_PER_MINUTE)->by('gezel-ip:'.$request->ip());
        });

        $this->rescueValidationOnGezelRoutes();
    }

    /**
     * A wrapping middleware can't catch a controller's ValidationException:
     * Illuminate\Routing\Pipeline renders exceptions to a Response at each
     * slice (middleware included), so nothing downstream of a slice ever
     * bubbles up to it as a throwable, and only the exception handler's own
     * renderable() hook sees the exception itself.
     *
     * Scoped by route name, not URL prefix: gezel.routes.prefix is a namespace
     * the host app also puts its own routes under, and turning that app's 422s
     * into 404s from inside a package is not ours to do.
     */
    private function rescueValidationOnGezelRoutes(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (! method_exists($handler, 'renderable')) {
            return;
        }

        $handler->renderable(function (ValidationException $e, Request $request) {
            $name = $request->route()?->getName();

            if (is_string($name) && str_starts_with($name, 'gezel.')) {
                return GezelRefusal::response();
            }
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
