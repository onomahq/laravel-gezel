<?php

namespace Onomahq\Gezel\Contracts;

use Onomahq\Gezel\Streaming\StreamEvent;
use Onomahq\Gezel\Streaming\StreamOutcome;
use Onomahq\Gezel\Streaming\StreamRequest;

/**
 * Raw cURL (the real implementation's transport) is invisible to
 * Http::fake(), so consumers depend on this interface instead of the concrete
 * client: swap in Testing\FakeGezelStream to test without a live SSE server.
 */
interface StreamsGezelChat
{
    /**
     * Stream one chat turn, handing each decoded SSE event to $onEvent as it
     * arrives. Never throws on a broken turn: a transport failure reaches
     * $onEvent as a StreamEventType::Error event, exactly as a gateway-reported
     * error does, and both return StreamOutcome::Failed. A consumer handles
     * every way a turn can break in one place.
     *
     * @param  callable(StreamEvent): void|null  $onEvent
     */
    public function stream(StreamRequest $request, ?callable $onEvent = null): StreamOutcome;

    /**
     * Request the in-flight turn for this chat to stop. Two-pronged: sets the
     * cache flag the streaming loop polls (works across process boundaries,
     * e.g. from a controller into a queued job), plus a best-effort direct
     * call to the gateway's abort endpoint.
     */
    public function requestStop(string $gezelId, string $externalChatId): void;
}
