<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\Compute\TranscriptionClient;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token');

    migrateGezelOwnerTable(GezelUser::class);
});

it('returns the transcript and its detected language', function () {
    Http::fake(['*' => Http::response(['text' => 'hallo daar', 'language' => 'nl'])]);

    expect(app(TranscriptionClient::class)->transcribe('bytes', 'audio/ogg'))
        ->toBe(['text' => 'hallo daar', 'language' => 'nl']);
});

it('meters against the owner gezel_id under the agent source', function () {
    Http::fake(['*' => Http::response(['text' => 'hi'])]);

    $owner = GezelUser::query()->create(['name' => 'Ada']);
    $owner->ensureGezelId();

    app(TranscriptionClient::class)->transcribe('bytes', 'audio/ogg', $owner->refresh());

    Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Usage-User-Id', $owner->gezel_id)
        && $r->hasHeader('X-Usage-Source', 'agent')
        && $r->hasHeader('X-Usage-Phase', 'chat'));
});

// Every failure answers null: a voice note whose transcript never arrived
// should still upload and still reach the agent.
it('answers null on an upstream failure', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    expect(app(TranscriptionClient::class)->transcribe('bytes', 'audio/ogg'))->toBeNull();
});

it('answers null when the middleware is not configured', function () {
    config()->set('gezel.middleware.app_token', '');

    expect(app(TranscriptionClient::class)->transcribe('bytes', 'audio/ogg'))->toBeNull();
});

it('answers null for empty audio without calling the middleware', function () {
    Http::fake();

    expect(app(TranscriptionClient::class)->transcribe('', 'audio/ogg'))->toBeNull();
    Http::assertNothingSent();
});

it('answers null for a blank transcript, which is not a usable result', function () {
    Http::fake(['*' => Http::response(['text' => '   '])]);

    expect(app(TranscriptionClient::class)->transcribe('bytes', 'audio/ogg'))->toBeNull();
});

it('answers null rather than a non-string language', function () {
    Http::fake(['*' => Http::response(['text' => 'hi', 'language' => ['nl']])]);

    expect(app(TranscriptionClient::class)->transcribe('bytes', 'audio/ogg'))
        ->toBe(['text' => 'hi', 'language' => null]);
});
