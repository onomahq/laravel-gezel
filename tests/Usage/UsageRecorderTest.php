<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Models\GezelUsageEvent;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;
use Onomahq\Gezel\Usage\UsageRecorder;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class);
    migrateGezelUsageTables();
});

afterEach(function () {
    Schema::dropIfExists('gezel_usage_events');
    Schema::dropIfExists('users');
});

function usageEvent(array $overrides = []): array
{
    return array_merge([
        'event_id' => 'f2b9a3a2-1111-4222-8333-444455556666',
        'user_id' => 'a2b9a3a2-1111-4222-8333-444455556666',
        'source' => 'chat',
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-5',
        'phase' => null,
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cache_creation_tokens' => 0,
        'cache_read_tokens' => 0,
        'occurred_at' => '2026-07-18T21:00:00Z',
        'context' => ['unit' => 'tokens'],
    ], $overrides);
}

it('persists the raw token counts and no cost', function () {
    $event = app(UsageRecorder::class)->record(usageEvent());

    expect($event->input_tokens)->toBe(1000)
        ->and($event->output_tokens)->toBe(500)
        ->and($event->cache_creation_tokens)->toBe(0)
        ->and($event->cache_read_tokens)->toBe(0)
        ->and($event->gezel_id)->toBe('a2b9a3a2-1111-4222-8333-444455556666')
        ->and($event->source)->toBe('chat')
        ->and($event->context)->toMatchArray(['unit' => 'tokens'])
        ->and($event->getAttributes())->not->toHaveKey('cost_usd')
        ->and($event->getAttributes())->not->toHaveKey('pricing_version');
});

it('stores the middleware context verbatim without adding pricing flags', function () {
    $event = app(UsageRecorder::class)->record(usageEvent([
        'context' => ['cost_usd_local' => 0.0105, 'pricing_version' => 3],
    ]));

    // The recorder no longer computes or flags cost; context passes through.
    expect($event->context)->toBe(['cost_usd_local' => 0.0105, 'pricing_version' => 3])
        ->and($event->context)->not->toHaveKey('pricing_fallback');
});

it('is idempotent on a retried event_id', function () {
    $recorder = app(UsageRecorder::class);

    $recorder->record(usageEvent());
    $recorder->record(usageEvent(['output_tokens' => 999999]));

    expect(GezelUsageEvent::query()->count())->toBe(1)
        ->and(GezelUsageEvent::query()->sole()->output_tokens)->toBe(500);
});

it('coerces a hostile payload into a row instead of rejecting it', function () {
    $event = app(UsageRecorder::class)->record([
        'source' => ['not' => 'a-string'],
        'input_tokens' => 'NaN',
        'output_tokens' => -50,
        'occurred_at' => 'not-a-date',
        'context' => 'not-an-array',
    ]);

    expect($event->exists)->toBeTrue()
        ->and($event->event_id)->toBeString()->not->toBe('')
        ->and($event->gezel_id)->toBeNull()
        ->and($event->source)->toBeNull()
        ->and($event->input_tokens)->toBe(0)
        ->and($event->output_tokens)->toBe(0)
        ->and($event->occurred_at)->not->toBeNull();
});

it('relates back to the owner through gezel_id', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    $gezelId = $owner->ensureGezelId();

    $event = app(UsageRecorder::class)->record(usageEvent(['user_id' => $gezelId]));

    expect($event->owner)->not->toBeNull()
        ->and($event->owner->getKey())->toBe($owner->getKey());
});

it('keeps the owner columns and the usage ledger on the connection gezel.connection names', function () {
    config(['database.connections.people' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'gezel.connection' => 'people']);
    Schema::connection('people')->create('users', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });

    foreach (['add_gezel_columns', 'add_gezel_usage'] as $stub) {
        $migration = include __DIR__."/../../database/migrations/{$stub}.php.stub";
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($migration->getConnection());
        $migration->up();
        DB::setDefaultConnection($previous);
    }

    app(UsageRecorder::class)->record(usageEvent(['event_id' => 'a1b9a3a2-1111-4222-8333-444455556666']));

    expect(Schema::connection('people')->hasColumn('users', 'gezel_id'))->toBeTrue()
        ->and(DB::connection('people')->table('gezel_usage_events')->count())->toBe(1)
        ->and(DB::table('gezel_usage_events')->count())->toBe(0);
});
