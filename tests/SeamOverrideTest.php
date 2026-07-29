<?php

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Auth\AlwaysAllowsWrites;
use Onomahq\Gezel\Contracts\AgentMessageHandler;
use Onomahq\Gezel\Contracts\ComputeUsageRecorder;
use Onomahq\Gezel\Contracts\OwnerMembershipVerifier;
use Onomahq\Gezel\Contracts\TargetOwnershipVerifier;
use Onomahq\Gezel\Contracts\TurnContextProvider;
use Onomahq\Gezel\Contracts\WritesGate;
use Onomahq\Gezel\GezelServiceProvider;
use Onomahq\Gezel\Support\Viewing;

/**
 * Every seam is bound with bindIf so a host app that bound its own first keeps
 * it. Nothing else pins that. Switching one to bind() breaks no test and no
 * boot — the host's implementation is simply replaced at runtime, which for
 * Onoma's ComputeUsageRecorder means compute usage silently stops reaching the
 * ledger billing reads.
 *
 * Re-running packageRegistered() after the host binding is the real sequence:
 * the host binds, the package boots second.
 */
function bootPackageOver(string $contract, string $implementation): mixed
{
    app()->bind($contract, $implementation);

    (new GezelServiceProvider(app()))->packageRegistered();

    return app()->make($contract);
}

it('keeps a host WritesGate over the package default', function () {
    expect(bootPackageOver(WritesGate::class, HostWritesGate::class))
        ->toBeInstanceOf(HostWritesGate::class);
});

it('keeps a host ComputeUsageRecorder, which is the binding billing depends on', function () {
    expect(bootPackageOver(ComputeUsageRecorder::class, HostUsageRecorder::class))
        ->toBeInstanceOf(HostUsageRecorder::class);
});

it('keeps a host AgentMessageHandler over the event-firing default', function () {
    expect(bootPackageOver(AgentMessageHandler::class, HostAgentMessageHandler::class))
        ->toBeInstanceOf(HostAgentMessageHandler::class);
});

it('keeps a host TurnContextProvider over the null default', function () {
    expect(bootPackageOver(TurnContextProvider::class, HostTurnContextProvider::class))
        ->toBeInstanceOf(HostTurnContextProvider::class);
});

it('keeps a host OwnerMembershipVerifier, so a suspended owner cannot fall back to always-allow', function () {
    expect(bootPackageOver(OwnerMembershipVerifier::class, HostMembershipVerifier::class))
        ->toBeInstanceOf(HostMembershipVerifier::class);
});

it('keeps a host TargetOwnershipVerifier over the deny-by-default', function () {
    expect(bootPackageOver(TargetOwnershipVerifier::class, HostTargetVerifier::class))
        ->toBeInstanceOf(HostTargetVerifier::class);
});

it('still supplies the default when the host bound nothing', function () {
    expect(app()->make(WritesGate::class))->toBeInstanceOf(AlwaysAllowsWrites::class);
});

class HostWritesGate implements WritesGate
{
    public function writesEnabled(Model $owner): bool
    {
        return false;
    }
}

class HostUsageRecorder implements ComputeUsageRecorder
{
    public function record(array $event): void {}
}

class HostAgentMessageHandler implements AgentMessageHandler
{
    public function handle(Model $owner, array $payload): void {}
}

class HostTurnContextProvider implements TurnContextProvider
{
    public function compose(Model $owner, ?Viewing $viewing = null): ?string
    {
        return null;
    }
}

class HostMembershipVerifier implements OwnerMembershipVerifier
{
    public function verify(Model $owner): bool
    {
        return false;
    }
}

class HostTargetVerifier implements TargetOwnershipVerifier
{
    public function verify(Model $owner, array $payload): bool
    {
        return false;
    }
}
