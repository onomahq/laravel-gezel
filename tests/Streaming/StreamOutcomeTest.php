<?php

use Onomahq\Gezel\Streaming\StreamOutcome;

it('resolves a turn outcome with stop winning over error', function () {
    expect(StreamOutcome::forTurn(stopped: false, errored: false))->toBe(StreamOutcome::Completed)
        ->and(StreamOutcome::forTurn(stopped: false, errored: true))->toBe(StreamOutcome::Failed)
        ->and(StreamOutcome::forTurn(stopped: true, errored: false))->toBe(StreamOutcome::Stopped)
        ->and(StreamOutcome::forTurn(stopped: true, errored: true))->toBe(StreamOutcome::Stopped);
});
