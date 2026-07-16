<?php

use Onomahq\Gezel\GezelServiceProvider;
use Onomahq\Gezel\Tests\Fixtures\GezelTeam;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

it('boots cleanly for the default User-subclass owner', function () {
    config()->set('gezel.owner.model', GezelUser::class);
    config()->set('gezel.owner.acknowledges_shared_memory', false);

    $provider = new GezelServiceProvider($this->app);

    expect(fn () => $provider->packageBooted())->not->toThrow(RuntimeException::class);
});

it('refuses to boot a non-User owner that has not acknowledged shared memory', function () {
    config()->set('gezel.owner.model', GezelTeam::class);
    config()->set('gezel.owner.acknowledges_shared_memory', false);

    $provider = new GezelServiceProvider($this->app);

    expect(fn () => $provider->packageBooted())->toThrow(RuntimeException::class, 'acknowledges_shared_memory');
});
