<?php

namespace Onomahq\Gezel\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
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
            GezelServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        // Default owner.model to a real, Authenticatable fixture so the
        // package boots cleanly; tests override gezel.owner.* as needed.
        config()->set('gezel.owner', [
            'model' => GezelUser::class,
            'acknowledges_shared_memory' => false,
        ]);
    }
}
