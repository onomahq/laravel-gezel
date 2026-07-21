<?php

use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Models\GezelUsageEvent;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class);
    migrateGezelUsageTables();
    config()->set('gezel.middleware.service_token', 'the-service-token');
});

afterEach(function () {
    Schema::dropIfExists('gezel_usage_events');
    Schema::dropIfExists('users');
});

function usageUri(): string
{
    return '/'.trim(config('gezel.routes.prefix'), '/').'/usage';
}

it('404s without the service token', function () {
    $this->postJson(usageUri(), ['event_id' => 'x'])
        ->assertNotFound()
        ->assertExactJson(['error' => 'not found']);
});

it('records a delivered event', function () {
    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(usageUri(), [
            'event_id' => 'f2b9a3a2-1111-4222-8333-444455556666',
            'user_id' => 'a2b9a3a2-1111-4222-8333-444455556666',
            'source' => 'transcribe',
            'provider' => 'mistral',
            'model' => 'voxtral-mini-latest',
            'input_tokens' => 42,
            'output_tokens' => 0,
            'occurred_at' => '2026-07-18T21:00:00Z',
            'context' => ['unit' => 'duration_seconds'],
        ])
        ->assertOk()
        ->assertExactJson(['status' => 'recorded']);

    $event = GezelUsageEvent::query()->sole();

    expect($event->source)->toBe('transcribe')
        ->and($event->input_tokens)->toBe(42)
        ->and($event->context)->toMatchArray(['unit' => 'duration_seconds']);
});

it('never answers a body-shaped surprise with a 4xx, which would dead-letter the event', function () {
    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(usageUri(), ['unexpected' => ['deeply' => ['nested' => true]]])
        ->assertOk();

    expect(GezelUsageEvent::query()->count())->toBe(1);
});

it('acknowledges an empty body without recording', function () {
    $this->withHeader('Authorization', 'Bearer the-service-token')
        ->postJson(usageUri(), [])
        ->assertOk()
        ->assertExactJson(['status' => 'ignored']);

    expect(GezelUsageEvent::query()->count())->toBe(0);
});

it('acknowledges a retried event without duplicating it', function () {
    $payload = ['event_id' => 'f2b9a3a2-1111-4222-8333-444455556666', 'user_id' => 'u1'];

    $this->withHeader('Authorization', 'Bearer the-service-token')->postJson(usageUri(), $payload)->assertOk();
    $this->withHeader('Authorization', 'Bearer the-service-token')->postJson(usageUri(), $payload)->assertOk();

    expect(GezelUsageEvent::query()->count())->toBe(1);
});
