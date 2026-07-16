<?php

namespace Onomahq\Gezel\Concerns;

use Illuminate\Support\Str;

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

    public function optIntoGezel(): void
    {
        $this->forceFill(['gezel_opted_in_at' => now()])->save();
    }
}
