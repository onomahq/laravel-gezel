<?php

namespace Onomahq\Gezel\Testing;

use Onomahq\Gezel\Contracts\StreamsGezelChat;
use Onomahq\Gezel\Streaming\StreamEvent;
use Onomahq\Gezel\Streaming\StreamEventType;
use Onomahq\Gezel\Streaming\StreamOutcome;
use Onomahq\Gezel\Streaming\StreamRequest;

/**
 * In-memory StreamsGezelChat: plays back a canned event sequence into the
 * caller's callback instead of opening a real connection. Playback takes raw
 * wire payloads, exactly what the gateway sends, and decodes them through the
 * same StreamEvent::fromWire() the real client uses, so a consumer's tests
 * cannot pass against an event shape production never produces.
 *
 *   $fake = (new FakeGezelStream)->playback([
 *       ['type' => 'token', 'content' => 'Hi'],
 *       ['type' => 'done', 'content' => 'Hi'],
 *   ]);
 *   $this->app->instance(StreamsGezelChat::class, $fake);
 */
class FakeGezelStream implements StreamsGezelChat
{
    /** @var list<StreamEvent> */
    protected array $events = [];

    /** @var list<StreamRequest> */
    protected array $calls = [];

    /** @var list<array{gezel_id: string, external_chat_id: string}> */
    protected array $stopRequests = [];

    protected ?string $failWith = null;

    protected ?int $stopAfter = null;

    /**
     * @param  list<array<string, mixed>>  $events  Raw wire payloads, e.g. ['type' => 'token', 'content' => 'Hi'].
     */
    public function playback(array $events): static
    {
        $this->events = [];

        foreach ($events as $wire) {
            $event = StreamEvent::fromWire($wire);

            if ($event !== null) {
                $this->events[] = $event;
            }
        }

        return $this;
    }

    /**
     * Break the turn after the played-back events, the way an unreachable
     * gateway does: an error event, then StreamOutcome::Failed.
     */
    public function failWith(string $message): static
    {
        $this->failWith = $message;

        return $this;
    }

    /**
     * Cut the turn short after $count events, the way requestStop() from
     * another process does: playback truncates and the call returns Stopped.
     */
    public function stopAfter(int $count): static
    {
        $this->stopAfter = $count;

        return $this;
    }

    public function stream(StreamRequest $request, ?callable $onEvent = null): StreamOutcome
    {
        $this->calls[] = $request;
        $errored = false;

        foreach ($this->events as $index => $event) {
            if ($this->stopAfter !== null && $index >= $this->stopAfter) {
                return StreamOutcome::Stopped;
            }

            $errored = $errored || $event->type === StreamEventType::Error;

            if ($onEvent !== null) {
                $onEvent($event);
            }
        }

        if ($this->failWith !== null) {
            if ($onEvent !== null) {
                $onEvent(StreamEvent::error($this->failWith));
            }

            return StreamOutcome::Failed;
        }

        return $errored ? StreamOutcome::Failed : StreamOutcome::Completed;
    }

    public function requestStop(string $gezelId, string $externalChatId): void
    {
        $this->stopRequests[] = ['gezel_id' => $gezelId, 'external_chat_id' => $externalChatId];
    }

    /** @return list<StreamRequest> */
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
