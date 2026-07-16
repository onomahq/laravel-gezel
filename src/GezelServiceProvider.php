<?php

namespace Onomahq\Gezel;

use Onomahq\Gezel\Contracts\StreamsGezelChat;
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
        $this->app->bind(StreamsGezelChat::class, GezelStreamClient::class);
    }
}
