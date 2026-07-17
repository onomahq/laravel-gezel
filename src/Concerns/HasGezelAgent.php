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

    public function ensureGezelId(): string
    {
        if ($this->gezel_id === null) {
            $this->forceFill(['gezel_id' => (string) Str::orderedUuid()])->save();
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
