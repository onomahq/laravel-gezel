<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\Compute\LlmClient;
use Onomahq\Gezel\Contracts\ComputeUsageRecorder;
use Onomahq\Gezel\Exceptions\UsageCapExceededException;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token');
    config()->set('gezel.timeout', 60);

    migrateGezelOwnerTable(GezelUser::class);

    $this->recorded = [];
    $this->app->bind(ComputeUsageRecorder::class, fn () => new class($this) implements ComputeUsageRecorder
    {
        public function __construct(private object $test) {}

        public function record(array $event): void
        {
            $this->test->recorded[] = $event;
        }
    });
});

function computeOwner(): GezelUser
{
    $owner = GezelUser::query()->create(['name' => 'Ada']);
    $owner->ensureGezelId();

    return $owner->refresh();
}

it('relays a completion through the middleware and returns the assistant content', function () {
    Http::fake(['*' => Http::response([
        'model' => 'gpt-4o-mini',
        'choices' => [['message' => ['content' => 'hello back']]],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
    ])]);

    $result = app(LlmClient::class)->chat('gpt-4o-mini', [['role' => 'user', 'content' => 'hi']]);

    expect($result['content'])->toBe('hello back')
        ->and($result['model'])->toBe('gpt-4o-mini')
        ->and($result['usage'])->toBe(['prompt_tokens' => 5, 'completion_tokens' => 2]);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'http://middleware.test/v1/chat/completions'
        && $r->hasHeader('Authorization', 'Bearer app-token')
        && $r['stream'] === false);
});

it('meters the call against the owner gezel_id, not its primary key', function () {
    Http::fake(['*' => Http::response([
        'model' => 'gpt-4o-mini',
        'choices' => [['message' => ['content' => 'ok']]],
        'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3],
    ])]);

    $owner = computeOwner();

    app(LlmClient::class)->chat('gpt-4o-mini', [['role' => 'user', 'content' => 'hi']], [
        'phase' => 'summary',
        'ledger_source' => 'ingest',
    ], $owner);

    Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Usage-User-Id', $owner->gezel_id)
        && $r->hasHeader('X-Usage-Source', 'platform')
        && $r->hasHeader('X-Usage-Phase', 'summary'));

    expect($this->recorded)->toHaveCount(1)
        ->and($this->recorded[0]['user_id'])->toBe($owner->gezel_id)
        ->and($this->recorded[0]['source'])->toBe('ingest')
        ->and($this->recorded[0]['phase'])->toBe('summary')
        ->and($this->recorded[0]['input_tokens'])->toBe(7)
        ->and($this->recorded[0]['output_tokens'])->toBe(3);
});

it('runs unmetered and sends no usage headers without an owner', function () {
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => 'ok']]],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
    ])]);

    app(LlmClient::class)->chat('gpt-4o-mini', [['role' => 'user', 'content' => 'hi']]);

    Http::assertSent(fn (Request $r): bool => ! $r->hasHeader('X-Usage-User-Id'));
    expect($this->recorded)->toBeEmpty();
});

it('makes exactly one attempt by default', function () {
    Http::fake(['*' => Http::response('upstream exploded', 500)]);

    expect(fn () => app(LlmClient::class)->chat('gpt-4o-mini', []))
        ->toThrow(RuntimeException::class, 'failed after 1 attempts');

    Http::assertSentCount(1);
});

it('retries a transient failure exactly as many times as the caller asked', function () {
    Http::fake(['*' => Http::sequence()
        ->push('nope', 500)
        ->push('nope', 500)
        ->push(['choices' => [['message' => ['content' => 'third time']]]], 200)]);

    $result = app(LlmClient::class)->chat('gpt-4o-mini', [], ['retries' => 2]);

    expect($result['content'])->toBe('third time');
    Http::assertSentCount(3);
});

it('never retries a permanent 4xx even when the caller asked for retries', function () {
    Http::fake(['*' => Http::response('bad request', 400)]);

    expect(fn () => app(LlmClient::class)->chat('gpt-4o-mini', [], ['retries' => 5]))
        ->toThrow(RuntimeException::class);

    Http::assertSentCount(1);
});

it('escapes the retry loop immediately when the monthly cap is exhausted', function () {
    Http::fake(['*' => Http::response([
        'error' => ['type' => 'usage_limit_exceeded', 'message' => 'Monthly cap reached.'],
        'reset' => '2026-08-01T00:00:00Z',
    ], 429)]);

    try {
        app(LlmClient::class)->chat('gpt-4o-mini', [], ['retries' => 5], computeOwner());
        $this->fail('Expected UsageCapExceededException.');
    } catch (UsageCapExceededException $e) {
        expect($e->getMessage())->toBe('Monthly cap reached.')
            ->and($e->resetsAt?->toDateString())->toBe('2026-08-01');
    }

    Http::assertSentCount(1);
});

it('keeps retrying a provider rate-limit, which is not a cap rejection', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => ['type' => 'rate_limit_error']], 429)
        ->push(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

    expect(app(LlmClient::class)->chat('gpt-4o-mini', [], ['retries' => 1])['content'])->toBe('ok');
    Http::assertSentCount(2);
});

it('records nothing when the response reports no tokens at all', function () {
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => 'ok']]],
        'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
    ])]);

    app(LlmClient::class)->chat('gpt-4o-mini', [], [], computeOwner());

    expect($this->recorded)->toBeEmpty();
});

it('refuses to call an unconfigured middleware', function () {
    config()->set('gezel.middleware.app_token', '');

    expect(fn () => app(LlmClient::class)->chat('gpt-4o-mini', []))
        ->toThrow(RuntimeException::class, 'gezel.middleware.url or gezel.middleware.app_token is not configured.');
});
