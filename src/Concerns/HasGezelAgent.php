<?php

namespace Onomahq\Gezel\Concerns;

use Illuminate\Support\Str;

trait HasGezelAgent
{
    public function ensureGezelId(): string
    {
        if ($this->gezel_id === null) {
            $this->gezel_id = (string) Str::uuid();
            $this->save();
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

        // TODO(laravel-gezel Module 6): dispatch the ProvisionContainer queued
        // job here once the provisioning strategy wiring ships.
    }
}
