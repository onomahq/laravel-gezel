<?php

namespace Onomahq\Gezel\Contracts;

/**
 * Raw cURL (the real implementation's transport) is invisible to
 * Http::fake(), so consumers depend on this interface instead of the
 * concrete client — swap in Testing\FakeGezelStream to test without a live
 * SSE server.
 */
interface StreamsGezelChat
{
    /**
     * Stream one chat turn. Each SSE event is dispatched to its matching
     * callback as it arrives.
     *
     * @param  callable(string): void|null  $onToken
     * @param  callable(array<string, mixed>): void|null  $onToolCall
     * @param  callable(array<string, mixed>): void|null  $onToolResult
     * @param  callable(?string): void|null  $onDone
     * @param  callable(string): void|null  $onError
     */
    public function stream(
        string $gezelId,
        string $externalChatId,
        string $text,
        ?string $personaId = null,
        ?string $turnContext = null,
        ?callable $onToken = null,
        ?callable $onToolCall = null,
        ?callable $onToolResult = null,
        ?callable $onDone = null,
        ?callable $onError = null,
    ): void;

    /**
     * Request the in-flight turn for this chat to stop. Two-pronged: sets
     * the cache flag the streaming loop polls (works across process
     * boundaries, e.g. from a controller into a queued job), plus a
     * best-effort direct call to the gateway's abort endpoint.
     */
    public function requestStop(string $gezelId, string $externalChatId): void;
}
