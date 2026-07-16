<?php

use Onomahq\Gezel\Streaming\StreamEvent;
use Onomahq\Gezel\Streaming\StreamEventType;
use Onomahq\Gezel\Streaming\StreamOutcome;
use Onomahq\Gezel\Streaming\StreamRequest;
use Onomahq\Gezel\Support\SseEventParser;
use Onomahq\Gezel\Testing\FakeGezelStream;

function collectFake(FakeGezelStream $fake, ?StreamRequest $request = null): array
{
    $events = [];

    $outcome = $fake->stream(
        $request ?? new StreamRequest('gezel-1', 'chat-1', 'hi'),
        function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        },
    );

    return [$outcome, $events];
}

it('plays a canned wire sequence back to the callback', function () {
    $fake = (new FakeGezelStream)->playback([
        ['type' => 'token', 'content' => 'Hel'],
        ['type' => 'token', 'content' => 'lo'],
        ['type' => 'tool_call', 'tool' => 'search'],
        ['type' => 'done', 'content' => 'Hello'],
    ]);

    [$outcome, $events] = collectFake($fake);

    expect($outcome)->toBe(StreamOutcome::Completed);
    expect(array_map(fn ($event) => $event->type, $events))->toBe([
        StreamEventType::Token,
        StreamEventType::Token,
        StreamEventType::ToolCall,
        StreamEventType::Done,
    ]);
    expect($events[0]->content())->toBe('Hel');
});

it('hands consumers the same event shape the real parser produces', function () {
    $wire = ['type' => 'tool_call', 'tool' => 'search'];

    [, $fakeEvents] = collectFake((new FakeGezelStream)->playback([$wire]));
    $parsed = (new SseEventParser)->push('data: '.json_encode($wire)."\n");

    expect($fakeEvents[0]->type)->toBe($parsed[0]->type);
    expect($fakeEvents[0]->data)->toBe($parsed[0]->data);
});

it('drops playback events the real parser would also drop', function () {
    [, $events] = collectFake((new FakeGezelStream)->playback([
        ['type' => 'heartbeat'],
        ['content' => 'no type'],
        ['type' => 'token', 'content' => 'hi'],
    ]));

    expect($events)->toHaveCount(1);
    expect($events[0]->content())->toBe('hi');
});

it('reproduces a stop from another process', function () {
    $fake = (new FakeGezelStream)
        ->playback([
            ['type' => 'token', 'content' => 'Hel'],
            ['type' => 'token', 'content' => 'lo'],
            ['type' => 'done', 'content' => 'Hello'],
        ])
        ->stopAfter(1);

    [$outcome, $events] = collectFake($fake);

    expect($outcome)->toBe(StreamOutcome::Stopped);
    expect($events)->toHaveCount(1);
});

it('reproduces a transport failure', function () {
    $fake = (new FakeGezelStream)
        ->playback([['type' => 'token', 'content' => 'Hel']])
        ->failWith('gateway went away');

    [$outcome, $events] = collectFake($fake);

    expect($outcome)->toBe(StreamOutcome::Failed);
    expect($events)->toHaveCount(2);
    expect($events[1]->type)->toBe(StreamEventType::Error);
    expect($events[1]->content())->toBe('gateway went away');
});

it('records each stream() call', function () {
    $fake = (new FakeGezelStream)->playback([]);
    $request = new StreamRequest('gezel-1', 'chat-1', 'hi', personaId: 'coach');

    $fake->stream($request);

    expect($fake->calls())->toBe([$request]);
});

it('records requestStop() calls without touching any cache', function () {
    $fake = new FakeGezelStream;

    $fake->requestStop('gezel-1', 'chat-1');

    expect($fake->stopRequests())->toBe([
        ['gezel_id' => 'gezel-1', 'external_chat_id' => 'chat-1'],
    ]);
});

it('tolerates a missing callback', function () {
    $fake = (new FakeGezelStream)->playback([['type' => 'token', 'content' => 'hi']]);

    expect($fake->stream(new StreamRequest('gezel-1', 'chat-1', 'hi')))->toBe(StreamOutcome::Completed);
});

it('treats a gateway error event as a failed turn, like a dead transport', function () {
    $fake = (new FakeGezelStream)->playback([
        ['type' => 'token', 'content' => 'Hel'],
        ['type' => 'error', 'content' => 'model refused'],
    ]);

    [$outcome, $events] = collectFake($fake);

    expect($outcome)->toBe(StreamOutcome::Failed);
    expect($events[1]->content())->toBe('model refused');
});
