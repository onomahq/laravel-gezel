<?php

use Onomahq\Gezel\Streaming\StreamEvent;
use Onomahq\Gezel\Streaming\StreamEventType;

it('builds an event from a wire payload and keeps the payload whole', function () {
    $event = StreamEvent::fromWire(['type' => 'tool_call', 'tool' => 'search']);

    expect($event)->not->toBeNull();
    expect($event->type)->toBe(StreamEventType::ToolCall);
    expect($event->data)->toBe(['type' => 'tool_call', 'tool' => 'search']);
});

it('returns null for an unknown or absent type', function (array $wire) {
    expect(StreamEvent::fromWire($wire))->toBeNull();
})->with([
    'unknown type' => [['type' => 'heartbeat', 'content' => 'ping']],
    'absent type' => [['content' => 'no type field']],
    'non-string type' => [['type' => 42]],
]);

it('reads the content field carried by token, done, and error events', function () {
    expect(StreamEvent::fromWire(['type' => 'token', 'content' => 'hi'])->content())->toBe('hi');
    expect(StreamEvent::fromWire(['type' => 'done'])->content())->toBeNull();
    expect(StreamEvent::fromWire(['type' => 'tool_call', 'content' => ['a']])->content())->toBeNull();
});

it('builds an error event that matches the wire shape', function () {
    $event = StreamEvent::error('boom');

    expect($event->type)->toBe(StreamEventType::Error);
    expect($event->data)->toBe(['type' => 'error', 'content' => 'boom']);
    expect($event->content())->toBe('boom');
});
