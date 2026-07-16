<?php

use Onomahq\Gezel\Testing\FakeGezelStream;

it('dispatches a canned event sequence to the matching callbacks', function () {
    $fake = (new FakeGezelStream)->playback([
        ['type' => 'token', 'data' => ['content' => 'Hel']],
        ['type' => 'token', 'data' => ['content' => 'lo']],
        ['type' => 'tool_call', 'data' => ['tool' => 'search']],
        ['type' => 'tool_result', 'data' => ['tool' => 'search', 'result' => 'ok']],
        ['type' => 'done', 'data' => ['content' => 'Hello']],
    ]);

    $tokens = [];
    $toolCalls = [];
    $toolResults = [];
    $done = null;

    $fake->stream(
        gezelId: 'gezel-1',
        externalChatId: 'chat-1',
        text: 'hi',
        onToken: function (string $token) use (&$tokens) {
            $tokens[] = $token;
        },
        onToolCall: function (array $event) use (&$toolCalls) {
            $toolCalls[] = $event;
        },
        onToolResult: function (array $event) use (&$toolResults) {
            $toolResults[] = $event;
        },
        onDone: function (?string $reply) use (&$done) {
            $done = $reply;
        },
    );

    expect($tokens)->toBe(['Hel', 'lo']);
    expect($toolCalls)->toBe([['tool' => 'search']]);
    expect($toolResults)->toBe([['tool' => 'search', 'result' => 'ok']]);
    expect($done)->toBe('Hello');
});

it('records each stream() call', function () {
    $fake = (new FakeGezelStream)->playback([]);

    $fake->stream('gezel-1', 'chat-1', 'hi', personaId: 'coach');

    expect($fake->calls())->toBe([
        ['gezel_id' => 'gezel-1', 'external_chat_id' => 'chat-1', 'text' => 'hi', 'persona_id' => 'coach', 'turn_context' => null],
    ]);
});

it('records requestStop() calls without touching any cache', function () {
    $fake = new FakeGezelStream;

    $fake->requestStop('gezel-1', 'chat-1');

    expect($fake->stopRequests())->toBe([
        ['gezel_id' => 'gezel-1', 'external_chat_id' => 'chat-1'],
    ]);
});

it('tolerates missing callbacks', function () {
    $fake = (new FakeGezelStream)->playback([
        ['type' => 'error', 'data' => ['content' => 'boom']],
    ]);

    expect(fn () => $fake->stream('gezel-1', 'chat-1', 'hi'))->not->toThrow(Throwable::class);
});
