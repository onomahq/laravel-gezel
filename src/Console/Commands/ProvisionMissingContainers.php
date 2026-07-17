<?php

namespace Onomahq\Gezel\Console\Commands;

use Illuminate\Console\Command;
use Onomahq\Gezel\Auth\BearerRotator;
use Onomahq\Gezel\Contracts\GezelOwner;
use Onomahq\Gezel\GezelOrchestrator;
use Onomahq\Gezel\Jobs\ProvisionContainer;
use Onomahq\Gezel\Support\Owner;
use Throwable;

/**
 * Provisions a Gezel container for every owner that lacks one. Covers the dev
 * bootstrap case (an owner's provision fired before the middleware was up)
 * and a prod healing tool for the hourly self-heal schedule.
 *
 * Under the 'opt-in' strategy only opted-in owners are eligible (mirrors
 * Stagent); under 'observer'/'manual' every owner lacking a container is
 * eligible (mirrors Onoma Platform), since there's no opt-in flag gating them.
 *
 * --force re-provisions owners that already have one too. For those, the
 * middleware's own provision endpoint answers AlreadyExists for a live
 * container and never rewrites its config, so resetting
 * gezel_provisioned_at and re-dispatching ProvisionContainer can't actually
 * repair anything; it only corrupts the flag on failure. --force instead
 * routes an already-provisioned owner through the same reconcile (mint,
 * recreate, revoke-old) gezel:reconcile-container-bearers uses.
 */
class ProvisionMissingContainers extends Command
{
    protected $signature = 'gezel:provision-missing
        {--force : Re-provision even owners that already have a container}';

    protected $description = 'Provision a Gezel container for every owner that lacks one.';

    public function handle(BearerRotator $rotator, GezelOrchestrator $orchestrator): int
    {
        $ownerModel = Owner::model();

        $owners = $ownerModel::query()
            ->when(
                config('gezel.provisioning.strategy') === 'opt-in',
                fn ($query) => $query->whereNotNull('gezel_opted_in_at'),
            )
            ->when(
                ! $this->option('force'),
                fn ($query) => $query->whereNull('gezel_provisioned_at'),
            )
            ->get();

        if ($owners->isEmpty()) {
            $this->info('No owners need provisioning.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($owners as $owner) {
            if (! $owner instanceof GezelOwner) {
                // Owner::model() already validated gezel.owner.model implements
                // GezelOwner above; unreachable in practice.
                continue;
            }

            try {
                if ($this->option('force') && $owner->gezelProvisioned()) {
                    $gezelId = $owner->ensureGezelId();

                    $rotator->reconcile($owner, function (string $bearer) use ($orchestrator, $gezelId): void {
                        $orchestrator->recreate($gezelId, $bearer);
                    });

                    $this->info("Reconciled already-provisioned owner {$owner->getKey()}.");

                    continue;
                }

                ProvisionContainer::dispatchSync($owner);
                $this->info("Provisioned Gezel container for owner {$owner->getKey()}.");
            } catch (Throwable $e) {
                $failed++;
                $this->error("Failed to provision for owner {$owner->getKey()}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
