<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\Compute\EmbeddingsClient;
use Onomahq\Gezel\Contracts\ComputeUsageRecorder;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token');

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

it('returns one vector per input', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['index' => 0, 'embedding' => [0.1, 0.2]],
        ['index' => 1, 'embedding' => [0.3, 0.4]],
    ]])]);

    expect(app(EmbeddingsClient::class)->embedMany(['a', 'b']))
        ->toBe([[0.1, 0.2], [0.3, 0.4]]);
});

it('reorders the response by index, since the provider may not preserve input order', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['index' => 1, 'embedding' => [0.3]],
        ['index' => 0, 'embedding' => [0.1]],
    ]])]);

    expect(app(EmbeddingsClient::class)->embedMany(['a', 'b']))->toBe([[0.1], [0.3]]);
});

it('sends the model and dimensions explicitly rather than relying on middleware injection', function () {
    Http::fake(['*' => Http::response(['data' => [['index' => 0, 'embedding' => [0.1]]]])]);

    app(EmbeddingsClient::class)->embed('hello');

    Http::assertSent(fn (Request $r): bool => $r['model'] === EmbeddingsClient::MODEL
        && $r['dimensions'] === EmbeddingsClient::DIMENSIONS);
});

it('truncates an oversized input rather than letting the provider reject the batch', function () {
    Http::fake(['*' => Http::response(['data' => [['index' => 0, 'embedding' => [0.1]]]])]);

    app(EmbeddingsClient::class)->embed(str_repeat('x', EmbeddingsClient::MAX_INPUT_CHARS + 500));

    Http::assertSent(fn (Request $r): bool => mb_strlen($r['input'][0]) === EmbeddingsClient::MAX_INPUT_CHARS);
});

it('meters against the owner gezel_id', function () {
    Http::fake(['*' => Http::response([
        'model' => 'text-embedding-3-small',
        'data' => [['index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 4],
    ])]);

    $owner = GezelUser::query()->create(['name' => 'Ada']);
    $owner->ensureGezelId();

    app(EmbeddingsClient::class)->embed('hello', $owner->refresh());

    Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Usage-User-Id', $owner->gezel_id)
        && $r->hasHeader('X-Usage-Phase', 'embedding'));

    expect($this->recorded[0]['source'])->toBe('embedding')
        ->and($this->recorded[0]['input_tokens'])->toBe(4)
        ->and($this->recorded[0]['output_tokens'])->toBe(0);
});

it('short-circuits an empty batch without calling the middleware', function () {
    Http::fake();

    expect(app(EmbeddingsClient::class)->embedMany([]))->toBe([]);
    Http::assertNothingSent();
});

it('refuses a payload whose item count does not match the input count', function () {
    Http::fake(['*' => Http::response(['data' => [['index' => 0, 'embedding' => [0.1]]]])]);

    expect(fn () => app(EmbeddingsClient::class)->embedMany(['a', 'b']))
        ->toThrow(RuntimeException::class, 'unexpected payload');
});

it('refuses an item that carries no embedding', function () {
    Http::fake(['*' => Http::response(['data' => [['index' => 0, 'embedding' => []]]])]);

    expect(fn () => app(EmbeddingsClient::class)->embed('a'))
        ->toThrow(RuntimeException::class, 'missing its embedding');
});

it('surfaces a failed call rather than returning a partial batch', function () {
    Http::fake(['*' => Http::response('upstream down', 502)]);

    expect(fn () => app(EmbeddingsClient::class)->embed('a'))
        ->toThrow(RuntimeException::class, 'Middleware /v1/embeddings failed: 502');
});
