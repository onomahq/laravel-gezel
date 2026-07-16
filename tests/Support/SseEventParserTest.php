<?php

use Onomahq\Gezel\Support\SseEventParser;

it('parses a token event from a single push', function () {
    $parser = new SseEventParser;

    $events = $parser->push("data: {\"type\":\"token\",\"content\":\"hi\"}\n");

    expect($events)->toBe([
        ['type' => 'token', 'data' => ['type' => 'token', 'content' => 'hi']],
    ]);
});

it('parses multiple events delivered in one chunk', function () {
    $parser = new SseEventParser;

    $events = $parser->push(
        "data: {\"type\":\"token\",\"content\":\"a\"}\n".
        "data: {\"type\":\"token\",\"content\":\"b\"}\n"
    );

    expect($events)->toHaveCount(2);
    expect($events[0]['data']['content'])->toBe('a');
    expect($events[1]['data']['content'])->toBe('b');
});

it('buffers a line split across two pushes', function () {
    $parser = new SseEventParser;

    $first = $parser->push('data: {"type":"token","con');
    $second = $parser->push("tent\":\"hi\"}\n");

    expect($first)->toBe([]);
    expect($second)->toBe([
        ['type' => 'token', 'data' => ['type' => 'token', 'content' => 'hi']],
    ]);
});

it('ignores the [DONE] sentinel', function () {
    $parser = new SseEventParser;

    $events = $parser->push("data: [DONE]\n");

    expect($events)->toBe([]);
});

it('ignores blank lines and lines without the data prefix', function () {
    $parser = new SseEventParser;

    $events = $parser->push("\nevent: ping\n\n");

    expect($events)->toBe([]);
});

it('ignores malformed JSON and events without a type', function () {
    $parser = new SseEventParser;

    $events = $parser->push(
        "data: not json\n".
        "data: {\"content\":\"no type field\"}\n"
    );

    expect($events)->toBe([]);
});

it('parses tool_call, tool_result, done, and error events', function () {
    $parser = new SseEventParser;

    $events = $parser->push(
        "data: {\"type\":\"tool_call\",\"tool\":\"search\"}\n".
        "data: {\"type\":\"tool_result\",\"tool\":\"search\",\"result\":\"ok\"}\n".
        "data: {\"type\":\"done\",\"content\":\"full reply\"}\n".
        "data: {\"type\":\"error\",\"content\":\"boom\"}\n"
    );

    expect(array_column($events, 'type'))->toBe(['tool_call', 'tool_result', 'done', 'error']);
});
