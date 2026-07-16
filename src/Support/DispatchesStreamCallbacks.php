<?php

namespace Onomahq\Gezel\Support;

/**
 * Shared token|tool_call|tool_result|done|error dispatch, used by both the
 * real ext-curl stream client and the in-memory fake so their callback
 * semantics can never drift apart.
 */
trait DispatchesStreamCallbacks
{
    /**
     * @param  array{type: string, data: array<string, mixed>}  $event
     * @param  callable(string): void|null  $onToken
     * @param  callable(array<string, mixed>): void|null  $onToolCall
     * @param  callable(array<string, mixed>): void|null  $onToolResult
     * @param  callable(?string): void|null  $onDone
     * @param  callable(string): void|null  $onError
     */
    protected function dispatchStreamEvent(
        array $event,
        ?callable $onToken,
        ?callable $onToolCall,
        ?callable $onToolResult,
        ?callable $onDone,
        ?callable $onError,
    ): void {
        match ($event['type']) {
            'token' => self::invoke($onToken, $event['data']['content'] ?? ''),
            'tool_call' => self::invoke($onToolCall, $event['data']),
            'tool_result' => self::invoke($onToolResult, $event['data']),
            'done' => self::invoke($onDone, $event['data']['content'] ?? null),
            'error' => self::invoke($onError, $event['data']['content'] ?? 'stream error'),
            default => null,
        };
    }

    private static function invoke(?callable $callback, mixed ...$args): void
    {
        if ($callback !== null) {
            $callback(...$args);
        }
    }
}
