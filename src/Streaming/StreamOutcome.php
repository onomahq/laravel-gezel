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
}
