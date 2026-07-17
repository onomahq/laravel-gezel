<?php

namespace Onomahq\Gezel\Console\Commands;

use Illuminate\Console\Command;
use Onomahq\Gezel\Auth\BearerRotator;
use Onomahq\Gezel\GezelOrchestrator;
use Onomahq\Gezel\Support\Owner;
use RuntimeException;
use Throwable;

/**
 * Rotates a fresh container bearer into already-running Gezel containers.
 *
 * Repairs the drift where this app's tokens changed (database reset, driver
 * rotation) but an existing container still holds the old bearer in its
 * /data/config.toml. Provisioning can't repair that: the middleware answers
 * AlreadyExists for a live container and never rewrites its config. Recreate
 * is the path that pushes a fresh bearer while preserving the volume.
 */
class ReconcileContainerBearers extends Command
{
    protected $signature = 'gezel:reconcile-container-bearers
        {--owner= : Reconcile a single owner id}
        {--dry-run : Show which owners would be reconciled without minting/recreating}';

    protected $description = 'Mint fresh Gezel container bearers and recreate existing containers so their persisted token matches this app.';

    public function handle(BearerRotator $rotator, GezelOrchestrator $orchestrator): int
    {
        $ownerModel = Owner::model();

        $owners = $ownerModel::query()
            ->whereNotNull('gezel_provisioned_at')
            ->when($this->option('owner'), fn ($query, $ownerId) => $query->whereKey($ownerId))
            ->orderBy('created_at')
            ->get();

        if ($owners->isEmpty()) {
            $this->info('No provisioned Gezel owners found.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($owners as $owner) {
                $gezelId = $owner->gezel_id ?? null;
                $this->line("Would reconcile Gezel bearer for owner {$owner->getKey()} [gezel_id: {$gezelId}]");
            }

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($owners as $owner) {
            try {
                $gezelId = $owner->gezel_id ?? null;

                if (! is_string($gezelId) || $gezelId === '') {
                    throw new RuntimeException("owner {$owner->getKey()} has no gezel_id despite being provisioned.");
                }

                $rotator->reconcile(
                    $owner,
                    function (string $bearer) use ($orchestrator, $gezelId): void {
                        $orchestrator->recreate($gezelId, $bearer);
                    },
                );

                $this->info("Reconciled Gezel bearer for owner {$owner->getKey()}.");
            } catch (Throwable $e) {
                $failed++;
                $this->error("Failed to reconcile for owner {$owner->getKey()}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
