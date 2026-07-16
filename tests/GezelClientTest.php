<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\GezelClient;

beforeEach(function () {
    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token-123');
});

it('proxies requests through /v1/proxy/{gezel_id} with the app_token and a request id', function () {
    Http::fake([
        'middleware.test/v1/proxy/gezel-1/v1/models' => Http::response(['data' => []], 200),
    ]);

    (new GezelClient)->models('gezel-1');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://middleware.test/v1/proxy/gezel-1/v1/models'
            && $request->hasHeader('Authorization', 'Bearer app-token-123')
            && $request->hasHeader('X-Request-Id');
    });
});

it('url-encodes the gezel id and chat id in proxied paths', function () {
    Http::fake(['middleware.test/*' => Http::response(['messages' => []], 200)]);

    (new GezelClient)->fetchHistory('gezel/../evil', 'chat 1');

    Http::assertSent(fn ($request) => str_starts_with(
        $request->url(),
        'http://middleware.test/v1/proxy/gezel%2F..%2Fevil/v1/sessions/chat%201/messages'
    ));
});

it('forwards the inbound X-Request-Id so one trace spans app, middleware, and Gezel', function () {
    Http::fake(['middleware.test/*' => Http::response(['data' => []], 200)]);

    request()->headers->set('X-Request-Id', 'trace-abc');

    (new GezelClient)->models('gezel-1');

    Http::assertSent(fn ($request) => $request->hasHeader('X-Request-Id', 'trace-abc'));
});

it('prefers the request_id attribute over the inbound header', function () {
    Http::fake(['middleware.test/*' => Http::response(['data' => []], 200)]);

    request()->headers->set('X-Request-Id', 'trace-header');
    request()->attributes->set('request_id', 'trace-attribute');

    (new GezelClient)->models('gezel-1');

    Http::assertSent(fn ($request) => $request->hasHeader('X-Request-Id', 'trace-attribute'));
});

it('mints a request id when there is no inbound one', function () {
    Http::fake(['middleware.test/*' => Http::response(['data' => []], 200)]);

    (new GezelClient)->models('gezel-1');

    Http::assertSent(fn ($request) => $request->header('X-Request-Id')[0] !== '');
});

it('filters fetchHistory to non-empty user/assistant turns', function () {
    Http::fake([
        'middleware.test/v1/proxy/gezel-1/v1/sessions/chat-1/messages*' => Http::response([
            'messages' => [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => ''],
                ['role' => 'tool', 'content' => 'raw tool output'],
                ['role' => 'assistant', 'content' => 'hello there'],
            ],
        ], 200),
    ]);

    $history = (new GezelClient)->fetchHistory('gezel-1', 'chat-1');

    expect($history)->toBe([
        ['role' => 'user', 'content' => 'hi'],
        ['role' => 'assistant', 'content' => 'hello there'],
    ]);
});

it('swallows an error response from activateSession and deleteSession', function () {
    Http::fake([
        'middleware.test/*' => Http::response([], 500),
    ]);

    (new GezelClient)->activateSession('gezel-1', 'chat-1');
    (new GezelClient)->deleteSession('gezel-1', 'chat-1');

    Http::assertSentCount(2);
});

it('swallows an unreachable middleware from activateSession and deleteSession', function () {
    Http::fake(fn () => throw new ConnectionException('unreachable'));

    expect(function () {
        (new GezelClient)->activateSession('gezel-1', 'chat-1');
        (new GezelClient)->deleteSession('gezel-1', 'chat-1');
    })->not->toThrow(Throwable::class);
});

it('sends deleteSession with agent_id as a query parameter', function () {
    Http::fake(['middleware.test/*' => Http::response([], 200)]);

    (new GezelClient)->deleteSession('gezel-1', 'chat-1');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'http://middleware.test/v1/proxy/gezel-1/v1/sessions/chat-1?agent_id=default');
});

it('normalizes the models() response shape', function () {
    Http::fake([
        'middleware.test/v1/proxy/gezel-1/v1/models' => Http::response([
            'data' => [
                ['id' => 'gpt-5', 'display_name' => 'GPT-5', 'owned_by' => 'openai', 'is_premium' => true],
            ],
        ], 200),
    ]);

    $models = (new GezelClient)->models('gezel-1');

    expect($models)->toBe([[
        'model' => 'gpt-5',
        'display_name' => 'GPT-5',
        'owned_by' => 'openai',
        'is_premium' => true,
        'is_european' => false,
        'can_access' => true,
    ]]);
});

it('normalizes the personas() response shape', function () {
    Http::fake([
        'middleware.test/v1/proxy/gezel-1/v1/personas' => Http::response([
            'default_persona_id' => 'coach',
            'data' => [
                ['id' => 'coach', 'name' => 'Coach', 'is_default' => true],
            ],
        ], 200),
    ]);

    $personas = (new GezelClient)->personas('gezel-1');

    expect($personas)->toBe([
        'default_persona_id' => 'coach',
        'data' => [
            ['id' => 'coach', 'name' => 'Coach', 'description' => null, 'is_default' => true],
        ],
    ]);
});

it('sends syncProfile as a PUT to /v1/me/profile', function () {
    Http::fake([
        'middleware.test/v1/proxy/gezel-1/v1/me/profile' => Http::response([], 200),
    ]);

    (new GezelClient)->syncProfile('gezel-1', ['name' => 'Ada', 'language' => 'nl']);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request['name'] === 'Ada'
        && $request['language'] === 'nl');
});

it('exposes generic verbs against the proxy base url', function () {
    Http::fake([
        'middleware.test/v1/proxy/gezel-1/custom/path' => Http::response(['ok' => true], 200),
    ]);

    $response = (new GezelClient)->get('gezel-1', '/custom/path');

    expect($response->json('ok'))->toBeTrue();
});
