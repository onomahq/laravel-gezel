<?php

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportIssuer;
use Onomahq\Gezel\Auth\Drivers\Passport\PassportVerifier;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumVerifier;
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

it('leaves the container-bearer bindings untouched for a custom driver value', function () {
    config()->set('gezel.auth.driver', 'custom-driver');

    $this->app->bind(ContainerBearerIssuer::class, fn () => new class implements ContainerBearerIssuer
    {
        public function issue(Model $owner): string
        {
            return 'app-supplied-bearer';
        }
    });

    (new GezelServiceProvider($this->app))->packageRegistered();

    expect($this->app->make(ContainerBearerIssuer::class)->issue(new GezelUser))
        ->toBe('app-supplied-bearer');
});
