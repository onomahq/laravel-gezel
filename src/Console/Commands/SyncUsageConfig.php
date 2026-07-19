<?php

namespace Onomahq\Gezel\Console\Commands;

use Illuminate\Console\Command;
use Onomahq\Gezel\Contracts\GezelOwner;
use Onomahq\Gezel\Support\Owner;
use Onomahq\Gezel\Usage\UsageConfigSync;
use Throwable;

/**
 * Bulk backfill: pushes the usage config (cap + pricing) for every
 * provisioned owner. The provision hook covers new owners; this covers a
 * pricing/cap change that must reach the whole fleet, and un-bricks a fleet
 * that provisioned before the middleware started enforcing metering.
 */
class SyncUsageConfig extends Command
{
    protected $signature = 'gezel:sync-usage-config
        {--owner-id= : Sync a single owner by primary key}';

    protected $description = 'Push the usage config (cap + pricing) to the middleware for every provisioned owner.';

    public function handle(UsageConfigSync $sync): int
    {
        if (! config('gezel.usage.enabled', true)) {
            $this->warn('gezel.usage.enabled is false; nothing pushed.');

            return self::SUCCESS;
        }

        $synced = 0;
        $failed = 0;

        Owner::model()::query()
            ->whereNotNull('gezel_id')
            ->whereNotNull('gezel_provisioned_at')
            ->when($this->option('owner-id'), fn ($query, $ownerId) => $query->whereKey($ownerId))
            ->chunkById(100, function ($owners) use ($sync, &$synced, &$failed): void {
                foreach ($owners as $owner) {
                    if (! $owner instanceof GezelOwner) {
                        continue;
                    }

                    try {
                        $sync->sync($owner);
                        $synced++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("Failed to sync usage config for owner {$owner->getKey()}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Usage config synced for {$synced} owner(s), {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
