<?php

namespace Onomahq\Gezel\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Passport\PassportServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Onomahq\Gezel\GezelServiceProvider;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Onomahq\\Gezel\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            SanctumServiceProvider::class,
            PassportServiceProvider::class,
            GezelServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        // Passport encrypts its RSA keys with the app key; harmless for
        // every other test that never touches Passport.
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        // Default owner.model to a real, Authenticatable fixture so the
        // package boots cleanly; tests override gezel.owner.* as needed.
        config()->set('gezel.owner', [
            'model' => GezelUser::class,
            'acknowledges_shared_memory' => false,
        ]);

        // Routes register once at boot, so the turn-context route (which is
        // opt-in per Module 4) needs enabling here, not per-test.
        config()->set('gezel.turn_context.enabled', true);
    }
}
