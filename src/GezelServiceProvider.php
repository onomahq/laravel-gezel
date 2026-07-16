<?php

namespace Onomahq\Gezel;

use Onomahq\Gezel\Commands\GezelCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GezelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-gezel')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_gezel_table')
            ->hasCommand(GezelCommand::class);
    }
}
