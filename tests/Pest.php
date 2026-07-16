<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
