<?php

namespace Onomahq\Gezel;

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
}
