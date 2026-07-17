<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\GezelServiceProvider;
use Onomahq\Gezel\Jobs\ProvisionContainer;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class);
});

afterEach(function () {
    Schema::dropIfExists('users');
});

it('dispatches ProvisionContainer when an owner is created under the observer strategy', function () {
    Bus::fake();
    config()->set('gezel.provisioning.strategy', 'observer');

    (new GezelServiceProvider($this->app))->packageBooted();

    $owner = GezelUser::create(['name' => 'Ada']);

    Bus::assertDispatched(ProvisionContainer::class, fn ($job) => $job->owner->is($owner));
});

it('does not register a created listener under the opt-in strategy', function () {
    Bus::fake();
    config()->set('gezel.provisioning.strategy', 'opt-in');

    (new GezelServiceProvider($this->app))->packageBooted();

    GezelUser::create(['name' => 'Ada']);

    Bus::assertNotDispatched(ProvisionContainer::class);
});

it('does not register a created listener under the manual strategy', function () {
    Bus::fake();
    config()->set('gezel.provisioning.strategy', 'manual');

    (new GezelServiceProvider($this->app))->packageBooted();

    GezelUser::create(['name' => 'Ada']);

    Bus::assertNotDispatched(ProvisionContainer::class);
});

it('does not register the observer listener when provisioning is disabled', function () {
    Bus::fake();
    config()->set('gezel.provisioning.strategy', 'observer');
    config()->set('gezel.provisioning.enabled', false);

    (new GezelServiceProvider($this->app))->packageBooted();

    GezelUser::create(['name' => 'Ada']);

    Bus::assertNotDispatched(ProvisionContainer::class);
});

it('registers hourly gezel:provision-missing when self_heal is enabled', function () {
    config()->set('gezel.provisioning.self_heal', true);

    (new GezelServiceProvider($this->app))->packageBooted();

    $matches = collect($this->app->make(Schedule::class)->events())
        ->contains(fn ($event) => str_contains((string) $event->command, 'gezel:provision-missing'));

    expect($matches)->toBeTrue();
});

it('does not register the schedule when self_heal is disabled', function () {
    config()->set('gezel.provisioning.self_heal', false);

    $before = collect($this->app->make(Schedule::class)->events())->count();

    (new GezelServiceProvider($this->app))->packageBooted();

    $after = collect($this->app->make(Schedule::class)->events())->count();

    expect($after)->toBe($before);
});
