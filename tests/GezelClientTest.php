<?php

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

it('swallows failures from activateSession and deleteSession', function () {
    Http::fake([
        'middleware.test/*' => Http::response([], 500),
    ]);

    (new GezelClient)->activateSession('gezel-1', 'chat-1');
    (new GezelClient)->deleteSession('gezel-1', 'chat-1');

    Http::assertSentCount(2);
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
