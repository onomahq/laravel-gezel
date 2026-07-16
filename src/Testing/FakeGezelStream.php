<?php

namespace Onomahq\Gezel\Testing;

use Onomahq\Gezel\Contracts\StreamsGezelChat;
use Onomahq\Gezel\Support\DispatchesStreamCallbacks;

/**
 * In-memory StreamsGezelChat: plays back a canned event sequence into the
 * caller's callbacks instead of opening a real connection. Bind it over the
 * interface in a consumer's test suite:
 *
 *   $fake = new FakeGezelStream();
 *   $this->app->instance(StreamsGezelChat::class, $fake->playback([...]));
 */
class FakeGezelStream implements StreamsGezelChat
{
    use DispatchesStreamCallbacks;

    /** @var list<array{type: string, data: array<string, mixed>}> */
    protected array $events = [];

    /** @var list<array{gezel_id: string, external_chat_id: string, text: string, persona_id: ?string, turn_context: ?string}> */
    protected array $calls = [];

    /** @var list<array{gezel_id: string, external_chat_id: string}> */
    protected array $stopRequests = [];

    /**
     * @param  list<array{type: string, data?: array<string, mixed>}>  $events
     */
    public function playback(array $events): static
    {
        $this->events = array_map(
            fn (array $event): array => ['type' => $event['type'], 'data' => $event['data'] ?? []],
            $events
        );

        return $this;
    }

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
    ): void {
        $this->calls[] = [
            'gezel_id' => $gezelId,
            'external_chat_id' => $externalChatId,
            'text' => $text,
            'persona_id' => $personaId,
            'turn_context' => $turnContext,
        ];

        foreach ($this->events as $event) {
            $this->dispatchStreamEvent($event, $onToken, $onToolCall, $onToolResult, $onDone, $onError);
        }
    }

    public function requestStop(string $gezelId, string $externalChatId): void
    {
        $this->stopRequests[] = ['gezel_id' => $gezelId, 'external_chat_id' => $externalChatId];
    }

    /** @return list<array{gezel_id: string, external_chat_id: string, text: string, persona_id: ?string, turn_context: ?string}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @return list<array{gezel_id: string, external_chat_id: string}> */
    public function stopRequests(): array
    {
        return $this->stopRequests;
    }
}
