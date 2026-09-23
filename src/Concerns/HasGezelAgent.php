<?php

namespace Onomahq\Gezel\Concerns;

use Illuminate\Support\Str;
use Onomahq\Gezel\Contracts\GezelOwner;
use Onomahq\Gezel\Jobs\ProvisionContainer;

/**
 * @phpstan-require-implements GezelOwner
 */
trait HasGezelAgent
{
    public function initializeHasGezelAgent(): void
    {
        $this->mergeCasts([
            'gezel_provisioned_at' => 'datetime',
            'gezel_opted_in_at' => 'datetime',
        ]);
    }

    /**
     * Mints with a conditional update and reads the winner back, so a stale
     * instance adopts the id another copy of this owner already persisted
     * instead of overwriting it. The sync queue restores a job's owner from
     * the database: an id ProvisionContainer mints never reaches the
     * dispatching instance, and saving a second one orphans the container.
     * The read-back uses the write connection: a replica may not have the
     * update yet.
     */
    public function ensureGezelId(): string
    {
        if ($this->gezel_id === null) {
            $this->newQueryWithoutScopes()
                ->whereKey($this->getKey())
                ->whereNull('gezel_id')
                ->update(['gezel_id' => (string) Str::orderedUuid()]);

            $this->setAttribute('gezel_id', $this->newQueryWithoutScopes()->useWritePdo()->whereKey($this->getKey())->value('gezel_id'));
            $this->syncOriginalAttribute('gezel_id');
        }

        return $this->gezel_id;
    }

    public function gezelProvisioned(): bool
    {
        return $this->gezel_provisioned_at !== null;
    }

    public function gezelOptedIn(): bool
    {
        return $this->gezel_opted_in_at !== null;
    }

    /**
     * Stamps opt-in always; only the 'opt-in' provisioning.strategy also
     * dispatches ProvisionContainer here. The 'observer' strategy provisions
     * from the owner model's `created` event instead, and 'manual' never
     * dispatches on its own.
     */
    public function optIntoGezel(): void
    {
        $this->forceFill(['gezel_opted_in_at' => now()])->save();

        if (config('gezel.provisioning.enabled', true) && config('gezel.provisioning.strategy') === 'opt-in') {
            ProvisionContainer::dispatch($this);
        }
    }
}
