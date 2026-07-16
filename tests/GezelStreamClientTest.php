<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\Contracts\StreamsGezelChat;
use Onomahq\Gezel\GezelStreamClient;

beforeEach(function () {
    config()->set('gezel.app_id', 'stagent');
    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token-123');
});

it('is bound to the StreamsGezelChat contract', function () {
    expect(app(StreamsGezelChat::class))->toBeInstanceOf(GezelStreamClient::class);
});

it('builds the InboundEnvelope with tenant_id from gezel.app_id', function () {
    $envelope = (new GezelStreamClient)->buildEnvelope('chat-1', 'hello');

    expect($envelope)->toBe([
        'tenant_id' => 'stagent',
        'platform' => 'web',
        'external_chat_id' => 'chat-1',
        'text' => 'hello',
    ]);
});

it('includes persona_id and turn_context in the envelope only when given', function () {
    $envelope = (new GezelStreamClient)->buildEnvelope('chat-1', 'hello', 'coach', 'the user is in a 1:1 with...');

    expect($envelope)->toBe([
        'tenant_id' => 'stagent',
        'platform' => 'web',
        'external_chat_id' => 'chat-1',
        'text' => 'hello',
        'persona_id' => 'coach',
        'turn_context' => 'the user is in a 1:1 with...',
    ]);
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
