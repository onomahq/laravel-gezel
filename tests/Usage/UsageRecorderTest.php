<?php

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
        'context' => ['cost_usd_local' => 0.0105, 'pricing_version' => 3],
    ], $overrides);
}

it('records the middleware-computed cost as authoritative', function () {
    $event = app(UsageRecorder::class)->record(usageEvent());

    expect($event->cost_usd)->toBe(0.0105)
        ->and($event->pricing_version)->toBe(3)
        ->and($event->gezel_id)->toBe('a2b9a3a2-1111-4222-8333-444455556666')
        ->and($event->context)->not->toHaveKey('pricing_fallback');
});

it('is idempotent on a retried event_id', function () {
    $recorder = app(UsageRecorder::class);

    $recorder->record(usageEvent());
    $recorder->record(usageEvent(['output_tokens' => 999999]));

    expect(GezelUsageEvent::query()->count())->toBe(1)
        ->and(GezelUsageEvent::query()->sole()->output_tokens)->toBe(500);
});

it('falls back to local pricing when cost_usd_local is absent, flagged as fallback', function () {
    $event = app(UsageRecorder::class)->record(usageEvent(['context' => []]));

    // 1000 in @ $3/M + 500 out @ $15/M = 0.003 + 0.0075
    expect($event->cost_usd)->toBe(0.0105)
        ->and($event->context)->toMatchArray(['pricing_fallback' => true]);
});

it('family-prefix matches an unknown model variant against its configured family', function () {
    $event = app(UsageRecorder::class)->record(usageEvent([
        'model' => 'claude-sonnet-4-6-20260101',
        'context' => [],
    ]));

    // Matches anthropic/claude-sonnet-4 pricing, not zero.
    expect($event->cost_usd)->toBe(0.0105);
});

it('bills an unpriced model at the priciest known rate, flagged pricing_unknown', function () {
    $event = app(UsageRecorder::class)->record(usageEvent([
        'provider' => 'mistral',
        'model' => 'voxtral-mini-latest',
        'context' => [],
    ]));

    // Priciest known rates are opus: 1000 in @ $15/M + 500 out @ $75/M.
    expect($event->cost_usd)->toBe(0.0525)
        ->and($event->context)->toMatchArray(['pricing_unknown' => true, 'pricing_fallback' => true]);
});

it('records zero only when no pricing is configured at all', function () {
    config()->set('gezel.usage.pricing.models', []);

    $event = app(UsageRecorder::class)->record(usageEvent(['context' => []]));

    expect($event->cost_usd)->toBe(0.0)
        ->and($event->context)->toMatchArray(['pricing_unknown' => true]);
});

it('keeps its own pricing flags when the middleware context carries same-named keys', function () {
    $event = app(UsageRecorder::class)->record(usageEvent([
        'context' => ['pricing_fallback' => 'middleware-said-so'],
    ]));

    // cost_usd_local absent → local fallback ran → the recorder's boolean
    // wins over the middleware's same-named key.
    expect($event->context['pricing_fallback'])->toBeTrue();
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
