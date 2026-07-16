<?php

use Onomahq\Gezel\Streaming\StreamEventType;
use Onomahq\Gezel\Support\SseEventParser;

it('parses a token event from a single push', function () {
    $events = (new SseEventParser)->push("data: {\"type\":\"token\",\"content\":\"hi\"}\n");

    expect($events)->toHaveCount(1);
    expect($events[0]->type)->toBe(StreamEventType::Token);
    expect($events[0]->content())->toBe('hi');
});

it('parses multiple events delivered in one chunk', function () {
    $events = (new SseEventParser)->push(
        "data: {\"type\":\"token\",\"content\":\"a\"}\n".
        "data: {\"type\":\"token\",\"content\":\"b\"}\n"
    );

    expect($events)->toHaveCount(2);
    expect($events[0]->content())->toBe('a');
    expect($events[1]->content())->toBe('b');
});

it('buffers a line split across two pushes', function () {
    $parser = new SseEventParser;

    $first = $parser->push('data: {"type":"token","con');
    $second = $parser->push("tent\":\"hi\"}\n");

    expect($first)->toBe([]);
    expect($second)->toHaveCount(1);
    expect($second[0]->content())->toBe('hi');
});

it('accepts data lines with and without the optional space', function () {
    $events = (new SseEventParser)->push("data:{\"type\":\"token\",\"content\":\"hi\"}\n");

    expect($events)->toHaveCount(1);
    expect($events[0]->content())->toBe('hi');
});

it('ignores the [DONE] sentinel', function () {
    expect((new SseEventParser)->push("data: [DONE]\n"))->toBe([]);
});

it('ignores blank lines and lines without the data prefix', function () {
    expect((new SseEventParser)->push("\nevent: ping\n\n"))->toBe([]);
});

it('ignores malformed JSON, events without a type, and unknown types', function () {
    $events = (new SseEventParser)->push(
        "data: not json\n".
        "data: {\"content\":\"no type field\"}\n".
        "data: {\"type\":\"heartbeat\"}\n"
    );

    expect($events)->toBe([]);
});

it('parses tool_call, tool_result, done, and error events', function () {
    $events = (new SseEventParser)->push(
        "data: {\"type\":\"tool_call\",\"tool\":\"search\"}\n".
        "data: {\"type\":\"tool_result\",\"tool\":\"search\",\"result\":\"ok\"}\n".
        "data: {\"type\":\"done\",\"content\":\"full reply\"}\n".
        "data: {\"type\":\"error\",\"content\":\"boom\"}\n"
    );

    expect(array_map(fn ($event) => $event->type, $events))->toBe([
        StreamEventType::ToolCall,
        StreamEventType::ToolResult,
        StreamEventType::Done,
        StreamEventType::Error,
    ]);
});

it('hands the whole decoded payload to the consumer', function () {
    $events = (new SseEventParser)->push("data: {\"type\":\"tool_call\",\"tool\":\"search\"}\n");

    expect($events[0]->data)->toBe(['type' => 'tool_call', 'tool' => 'search']);
});
