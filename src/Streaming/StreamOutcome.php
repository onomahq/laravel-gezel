<?php

namespace Onomahq\Gezel\Streaming;

/**
 * How a turn ended. Stopped is not a failure: it is what requestStop() from
 * another process looks like to the process doing the streaming, which
 * otherwise has no way to tell a stopped turn from a finished one.
 */
enum StreamOutcome: string
{
    case Completed = 'completed';
    case Stopped = 'stopped';
    case Failed = 'failed';

    /**
     * The one place the outcome rule lives: a stop wins over an error, an
     * error wins over completion. Both the real client and the fake call this.
     */
    public static function forTurn(bool $stopped, bool $errored): self
    {
        return match (true) {
            $stopped => self::Stopped,
            $errored => self::Failed,
            default => self::Completed,
        };
    }
}
