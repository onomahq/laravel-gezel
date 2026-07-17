<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\ClientRepository;
use Onomahq\Gezel\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Creates a minimal users table, points gezel.owner.model at it, then runs
 * the real add_gezel_columns migration stub to add the gezel_* columns.
 */
function migrateGezelOwnerTable(string $ownerModelClass): void
{
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    config()->set('gezel.owner.model', $ownerModelClass);

    (include __DIR__.'/../database/migrations/add_gezel_columns.php.stub')->up();
}

/**
 * Runs Sanctum's real create_personal_access_tokens_table migration so
 * driver tests exercise actual PAT lookup, not a fake.
 *
 * The filename is pinned to vendor: a Sanctum release that renames or moves
 * this migration breaks these tests with a file-not-found, not a assertion
 * failure. That is the trade for testing against the real schema.
 */
function migratePersonalAccessTokensTable(): void
{
    (include __DIR__.'/../vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php')->up();
}

/**
 * Runs Passport's real oauth_* migrations, generates real RSA keys, and
 * registers a personal-access-grant client for $provider: the minimum a
 * PassportVerifier test needs to issue and validate a genuine bearer.
 *
 * These filenames are pinned to vendor for the same reason, and with the same
 * trade, as migratePersonalAccessTokensTable() above.
 */
function migratePassportTables(string $provider): void
{
    foreach ([
        '2016_06_01_000001_create_oauth_auth_codes_table.php',
        '2016_06_01_000002_create_oauth_access_tokens_table.php',
        '2016_06_01_000003_create_oauth_refresh_tokens_table.php',
        '2016_06_01_000004_create_oauth_clients_table.php',
        '2024_06_01_000001_create_oauth_device_codes_table.php',
    ] as $migration) {
        (include __DIR__."/../vendor/laravel/passport/database/migrations/{$migration}")->up();
    }

    Artisan::call('passport:keys', ['--force' => true]);

    app(ClientRepository::class)->createPersonalAccessGrantClient('Gezel Test Client', $provider);
}
