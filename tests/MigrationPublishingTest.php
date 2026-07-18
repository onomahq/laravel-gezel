<?php

use Illuminate\Support\Facades\File;

it('publishes the add_gezel_columns migration stub under the gezel-migrations tag', function () {
    File::deleteDirectory(database_path('migrations'));

    $this->artisan('vendor:publish', ['--tag' => 'gezel-migrations', '--force' => true])
        ->assertExitCode(0);

    $published = File::glob(database_path('migrations/*_add_gezel_columns.php'));

    expect($published)->not->toBeEmpty();

    File::deleteDirectory(database_path('migrations'));
});

it('publishes the add_gezel_usage migration stub under the gezel-migrations tag', function () {
    File::deleteDirectory(database_path('migrations'));

    $this->artisan('vendor:publish', ['--tag' => 'gezel-migrations', '--force' => true])
        ->assertExitCode(0);

    $published = File::glob(database_path('migrations/*_add_gezel_usage.php'));

    expect($published)->not->toBeEmpty();

    File::deleteDirectory(database_path('migrations'));
});
