<?php

namespace Onomahq\Gezel\Console\Commands;

use Illuminate\Console\Command;
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
 */
class ProvisionMissingContainers extends Command
{
    protected $signature = 'gezel:provision-missing
        {--force : Re-provision even owners that already have a container}';

    protected $description = 'Provision a Gezel container for every owner that lacks one.';

    public function handle(): int
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
            try {
                if ($this->option('force')) {
                    $owner->forceFill(['gezel_provisioned_at' => null])->save();
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
