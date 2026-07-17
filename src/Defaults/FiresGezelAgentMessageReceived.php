<?php

namespace Onomahq\Gezel\Defaults;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Contracts\AgentMessageHandler;
use Onomahq\Gezel\Events\GezelAgentMessageReceived;

/**
 * Ships as the default {@see AgentMessageHandler} so a fresh install works
 * day one. Apps override this binding (e.g. Onoma broadcasts an `AgentMessage`
 * event over Reverb instead).
 */
final class FiresGezelAgentMessageReceived implements AgentMessageHandler
{
    public function handle(Model $owner, array $payload): void
    {
        event(new GezelAgentMessageReceived($owner, $payload));
    }
}
