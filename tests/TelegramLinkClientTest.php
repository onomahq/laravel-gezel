<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\TelegramLinkClient;

beforeEach(function () {
    config()->set('gezel.app_id', 'stagent');
    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token-123');
});

it('mints a deep link', function () {
    Http::fake([
        'middleware.test/v1/connectors/telegram/links' => Http::response([
            'deep_link' => 'https://t.me/bot?start=xyz',
            'bot_username' => 'bot',
            'expires_at' => '2026-01-01T00:00:00Z',
        ], 200),
    ]);

    $link = (new TelegramLinkClient)->link('gezel-1');

    expect($link['deep_link'])->toBe('https://t.me/bot?start=xyz');

    Http::assertSent(fn ($request) => $request['user_id'] === 'gezel-1');
});

it('degrades gracefully when linking fails', function () {
    Http::fake([
        'middleware.test/*' => Http::response([], 500),
    ]);

    $link = (new TelegramLinkClient)->link('gezel-1');

    expect($link)->toBe(['deep_link' => null, 'bot_username' => null, 'expires_at' => null]);
});

it('caches status keyed by app_id and gezel_id', function () {
    Http::fake([
        'middleware.test/v1/connectors/telegram/links/gezel-1' => Http::response([
            'connected' => true,
            'bot_username' => 'bot',
        ], 200),
    ]);

    (new TelegramLinkClient)->status('gezel-1');

    expect(Cache::get('gezel:stagent:telegram-status:gezel-1'))->toBe([
        'connected' => true,
        'bot_username' => 'bot',
    ]);
});

it('cachedStatus reads the cache without a middleware round-trip', function () {
    Cache::put('gezel:stagent:telegram-status:gezel-1', ['connected' => true, 'bot_username' => 'bot'], now()->addDay());

    Http::fake();

    $status = (new TelegramLinkClient)->cachedStatus('gezel-1');

    expect($status)->toBe(['connected' => true, 'bot_username' => 'bot']);
    Http::assertNothingSent();
});

it('cachedStatus falls back to a live check on a cache miss', function () {
    Http::fake([
        'middleware.test/v1/connectors/telegram/links/gezel-1' => Http::response([
            'connected' => false,
            'bot_username' => null,
        ], 200),
    ]);

    $status = (new TelegramLinkClient)->cachedStatus('gezel-1');

    expect($status)->toBe(['connected' => false, 'bot_username' => null]);
});

it('cachedStatus returns null when the middleware is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('unreachable'));

    expect((new TelegramLinkClient)->cachedStatus('gezel-1'))->toBeNull();
});

it('unlink forgets the cache and best-effort deletes the link', function () {
    Cache::put('gezel:stagent:telegram-status:gezel-1', ['connected' => true, 'bot_username' => 'bot'], now()->addDay());

    Http::fake([
        'middleware.test/v1/connectors/telegram/links/gezel-1' => Http::response([], 200),
    ]);

    (new TelegramLinkClient)->unlink('gezel-1');

    expect(Cache::get('gezel:stagent:telegram-status:gezel-1'))->toBeNull();
});

it('markLinked keeps a bot_username it already knows', function () {
    Http::fake();

    Cache::put('gezel:stagent:telegram-status:gezel-1', ['connected' => false, 'bot_username' => 'bot'], now()->addDay());

    (new TelegramLinkClient)->markLinked('gezel-1');

    expect(Cache::get('gezel:stagent:telegram-status:gezel-1'))->toBe(['connected' => true, 'bot_username' => 'bot']);
    Http::assertNothingSent();
});

it('status falls back to disconnected when the middleware cannot say', function () {
    Http::fake(fn () => throw new ConnectionException('unreachable'));

    expect((new TelegramLinkClient)->status('gezel-1'))->toBe(['connected' => false, 'bot_username' => null]);
});

it('url-encodes the gezel id in link paths', function () {
    Http::fake(['middleware.test/*' => Http::response(['connected' => true], 200)]);

    (new TelegramLinkClient)->status('gezel/../evil');

    Http::assertSent(fn ($request) => $request->url() === 'http://middleware.test/v1/connectors/telegram/links/gezel%2F..%2Fevil');
});

it('markLinked caches connected=true without calling the middleware', function () {
    Http::fake();

    (new TelegramLinkClient)->markLinked('gezel-1');

    expect(Cache::get('gezel:stagent:telegram-status:gezel-1'))->toBe(['connected' => true, 'bot_username' => null]);
    Http::assertNothingSent();
});
