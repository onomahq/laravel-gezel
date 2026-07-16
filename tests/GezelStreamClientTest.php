<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\Contracts\StreamsGezelChat;
use Onomahq\Gezel\GezelStreamClient;
use Onomahq\Gezel\Streaming\StreamEvent;
use Onomahq\Gezel\Streaming\StreamEventType;
use Onomahq\Gezel\Streaming\StreamOutcome;
use Onomahq\Gezel\Streaming\StreamRequest;

beforeEach(function () {
    config()->set('gezel.app_id', 'stagent');
    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token-123');
});

function streamCurlOptions(?StreamRequest $request = null): array
{
    return (new GezelStreamClient)->curlOptions(
        $request ?? new StreamRequest('gezel-1', 'chat-1', 'hello'),
        fn (): int => 0,
        fn (): int => 0,
    );
}

it('is bound to the StreamsGezelChat contract', function () {
    expect(app(StreamsGezelChat::class))->toBeInstanceOf(GezelStreamClient::class);
});

it('posts the envelope to the proxied stream url', function () {
    $options = streamCurlOptions();

    expect($options[CURLOPT_URL])->toBe('http://middleware.test/v1/proxy/gezel-1/v1/channels/inbound/stream');
    expect($options[CURLOPT_POST])->toBeTrue();
    expect(json_decode($options[CURLOPT_POSTFIELDS], true))->toBe([
        'tenant_id' => 'stagent',
        'platform' => 'web',
        'external_chat_id' => 'chat-1',
        'text' => 'hello',
    ]);
});

it('sends the app_token and asks for an event stream', function () {
    expect(streamCurlOptions()[CURLOPT_HTTPHEADER])->toBe([
        'Authorization: Bearer app-token-123',
        'Content-Type: application/json',
        'Accept: text/event-stream',
    ]);
});

it('url-encodes the gezel id in the stream url', function () {
    $options = streamCurlOptions(new StreamRequest('gezel/../evil', 'chat-1', 'hello'));

    expect($options[CURLOPT_URL])->toBe('http://middleware.test/v1/proxy/gezel%2F..%2Fevil/v1/channels/inbound/stream');
});

it('budgets a turn with the stream config, not the unary timeout', function () {
    config()->set('gezel.timeout', 120);
    config()->set('gezel.stream.connect_timeout', 7);
    config()->set('gezel.stream.idle_timeout', 90);
    config()->set('gezel.stream.max_duration', 900);

    $options = streamCurlOptions();

    expect($options[CURLOPT_CONNECTTIMEOUT])->toBe(7);
    expect($options[CURLOPT_TIMEOUT])->toBe(900);
    expect($options[CURLOPT_LOW_SPEED_TIME])->toBe(90);
    expect($options[CURLOPT_LOW_SPEED_LIMIT])->toBe(1);
});

it('enables the progress callback so a stop is seen while the stream is silent', function () {
    $options = streamCurlOptions();

    expect($options[CURLOPT_NOPROGRESS])->toBeFalse();
    expect($options[CURLOPT_XFERINFOFUNCTION])->toBeCallable();
    expect($options[CURLOPT_WRITEFUNCTION])->toBeCallable();
});

it('derives a stop key scoped to app_id, gezel_id, and chat id', function () {
    expect((new GezelStreamClient)->stopKey('gezel-1', 'chat-1'))
        ->toBe('gezel:stagent:stop:gezel-1:chat-1');
});

it('requestStop sets the cache flag the stream loop polls', function () {
    Http::fake();

    (new GezelStreamClient)->requestStop('gezel-1', 'chat-1');

    expect(Cache::get('gezel:stagent:stop:gezel-1:chat-1'))->toBeTrue();
});

it('keeps the stop flag alive for as long as a turn can run', function () {
    Http::fake();
    config()->set('gezel.stream.max_duration', 900);

    $this->freezeTime();

    (new GezelStreamClient)->requestStop('gezel-1', 'chat-1');

    $this->travel(899)->seconds();
    expect(Cache::get('gezel:stagent:stop:gezel-1:chat-1'))->toBeTrue();

    $this->travel(2)->seconds();
    expect(Cache::get('gezel:stagent:stop:gezel-1:chat-1'))->toBeNull();
});

it('requestStop best-effort posts to the abort endpoint', function () {
    Http::fake([
        'middleware.test/v1/proxy/gezel-1/v1/channels/inbound/stop' => Http::response([], 200),
    ]);

    (new GezelStreamClient)->requestStop('gezel-1', 'chat-1');

    Http::assertSent(fn ($request) => $request->url() === 'http://middleware.test/v1/proxy/gezel-1/v1/channels/inbound/stop'
        && $request->hasHeader('Authorization', 'Bearer app-token-123')
        && $request['platform'] === 'web'
        && $request['external_chat_id'] === 'chat-1');
});

it('requestStop never throws when the abort call fails', function () {
    Http::fake(fn () => throw new ConnectionException('unreachable'));

    expect(fn () => (new GezelStreamClient)->requestStop('gezel-1', 'chat-1'))->not->toThrow(Throwable::class);
    expect(Cache::get('gezel:stagent:stop:gezel-1:chat-1'))->toBeTrue();
});

it('reports an unreachable gateway as an error event and a Failed outcome', function () {
    // Port 1 refuses instantly, so this exercises the real cURL failure path
    // without standing up a server.
    config()->set('gezel.middleware.url', 'http://127.0.0.1:1');

    $events = [];

    $outcome = (new GezelStreamClient)->stream(
        new StreamRequest('gezel-1', 'chat-1', 'hello'),
        function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        },
    );

    expect($outcome)->toBe(StreamOutcome::Failed);
    expect($events)->toHaveCount(1);
    expect($events[0]->type)->toBe(StreamEventType::Error);
    expect($events[0]->content())->toStartWith('Gezel stream failed:');
});

it('does not throw when the gateway is unreachable and no callback is given', function () {
    config()->set('gezel.middleware.url', 'http://127.0.0.1:1');

    $outcome = (new GezelStreamClient)->stream(new StreamRequest('gezel-1', 'chat-1', 'hello'));

    expect($outcome)->toBe(StreamOutcome::Failed);
});

it('clears a stale stop flag when a new turn starts', function () {
    config()->set('gezel.middleware.url', 'http://127.0.0.1:1');

    Cache::put('gezel:stagent:stop:gezel-1:chat-1', true, now()->addMinutes(10));

    (new GezelStreamClient)->stream(new StreamRequest('gezel-1', 'chat-1', 'hello'));

    expect(Cache::get('gezel:stagent:stop:gezel-1:chat-1'))->toBeNull();
});
