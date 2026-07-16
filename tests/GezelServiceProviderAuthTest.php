<?php

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportIssuer;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportVerifier;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumVerifier;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Onomahq\Gezel\GezelServiceProvider;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

it('binds the sanctum driver by default', function () {
    config()->set('gezel.auth.driver', 'sanctum');

    (new GezelServiceProvider($this->app))->packageRegistered();

    expect($this->app->make(ContainerBearerIssuer::class))->toBeInstanceOf(SanctumIssuer::class);
    expect($this->app->make(PrincipalVerifier::class))->toBeInstanceOf(SanctumVerifier::class);
});

it('binds the passport driver when configured', function () {
    config()->set('gezel.auth.driver', 'passport');

    (new GezelServiceProvider($this->app))->packageRegistered();

    expect($this->app->make(ContainerBearerIssuer::class))->toBeInstanceOf(PassportIssuer::class);
    expect($this->app->make(PrincipalVerifier::class))->toBeInstanceOf(PassportVerifier::class);
});

it('binds a class-string driver implementing both contracts to both interfaces', function () {
    $custom = new class implements ContainerBearerIssuer, PrincipalVerifier
    {
        public function issue(Model $owner): string
        {
            return 'app-supplied-bearer';
        }

        public function verify(string $bearer): ?GezelPrincipal
        {
            return null;
        }
    };

    config()->set('gezel.auth.driver', $custom::class);
    $this->app->singleton($custom::class, fn () => $custom);

    (new GezelServiceProvider($this->app))->packageRegistered();

    expect($this->app->make(ContainerBearerIssuer::class)->issue(new GezelUser))
        ->toBe('app-supplied-bearer');
    expect($this->app->make(PrincipalVerifier::class))->toBe($custom);
});

it('throws a clear exception naming the bad value for an unresolvable custom driver', function () {
    config()->set('gezel.auth.driver', 'not-a-real-class');

    expect(fn () => (new GezelServiceProvider($this->app))->packageRegistered())
        ->toThrow(RuntimeException::class, 'not-a-real-class');
});

it('binds sanctum/passport lazily, so registering never resolves the driver', function () {
    // A missing laravel/sanctum install must only fail the request that
    // actually resolves a driver, never every artisan command booting the
    // framework. Asserting resolved() stays false is the only way to prove
    // that from a test: laravel/sanctum is always installed here, so
    // resolving would never throw either way, but an eager check would
    // still have run inside packageRegistered() itself.
    config()->set('gezel.auth.driver', 'sanctum');

    (new GezelServiceProvider($this->app))->packageRegistered();

    expect($this->app->resolved(ContainerBearerIssuer::class))->toBeFalse();
    expect($this->app->resolved(PrincipalVerifier::class))->toBeFalse();

    $this->app->make(ContainerBearerIssuer::class);

    expect($this->app->resolved(ContainerBearerIssuer::class))->toBeTrue();
});
