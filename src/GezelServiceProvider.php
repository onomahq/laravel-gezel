<?php

namespace Onomahq\Gezel;

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

    public function packageBooted(): void
    {
        Owner::guardSharedMemoryAcknowledgement();
    }
}
