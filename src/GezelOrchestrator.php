<?php

namespace Onomahq\Gezel;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\Exceptions\ContainerLifecycleDisabledException;
use RuntimeException;

class GezelOrchestrator
{
    public function provision(string $gezelId, string $containerToken): ContainerInfo
    {
        $response = $this->middleware()->post("/v1/containers/{$gezelId}/provision", [
            'container_token' => $containerToken,
        ]);

        return $this->containerInfoFrom($response, $gezelId, 'provision');
    }

    /**
     * Recreate the container with a freshly-minted bearer token. The volume
     * mount survives, so memory.db / agent.db / chat history are preserved
     * across the rotation.
     */
    public function recreate(string $gezelId, string $containerToken): ContainerInfo
    {
        $response = $this->middleware()->post("/v1/containers/{$gezelId}/recreate", [
            'container_token' => $containerToken,
        ]);

        return $this->containerInfoFrom($response, $gezelId, 'recreate');
    }

    public function deprovision(string $gezelId): bool
    {
        $response = $this->middleware()->delete("/v1/containers/{$gezelId}");

        $this->guardLifecycleDisabled($response, $gezelId);

        if ($response->failed()) {
            throw new RuntimeException("Failed to deprovision Gezel container for {$gezelId}");
        }

        return true;
    }

    public function restart(string $gezelId): bool
    {
        $response = $this->middleware()->post("/v1/containers/{$gezelId}/restart");

        $this->guardLifecycleDisabled($response, $gezelId);

        return $response->successful();
    }

    public function healthCheck(string $gezelId): HealthStatus
    {
        try {
            $response = $this->middleware()->get("/v1/containers/{$gezelId}/health");

            if ($response->successful()) {
                return HealthStatus::healthy($response->json('uptime_seconds'));
            }

            return HealthStatus::unhealthy("Health check returned {$response->status()}");
        } catch (\Throwable $e) {
            return HealthStatus::unhealthy($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function writeConfig(string $gezelId, array $config, int $version = 1): void
    {
        $response = $this->middleware()->post("/v1/containers/{$gezelId}/config", [
            'version' => $version,
            'payload' => $config,
        ]);

        $this->guardLifecycleDisabled($response, $gezelId);

        if ($response->failed()) {
            throw new RuntimeException("Failed to write config for {$gezelId}");
        }
    }

    protected function containerInfoFrom(Response $response, string $gezelId, string $action): ContainerInfo
    {
        $this->guardLifecycleDisabled($response, $gezelId);

        if ($response->failed()) {
            throw new RuntimeException("Failed to {$action} Gezel container for {$gezelId}");
        }

        $containerId = $response->json('container_id');

        if (! is_string($containerId) || $containerId === '') {
            throw new RuntimeException("Failed to {$action} Gezel container for {$gezelId}");
        }

        return new ContainerInfo(
            containerId: $containerId,
            status: $response->json('status', 'provisioned'),
        );
    }

    protected function guardLifecycleDisabled(Response $response, string $gezelId): void
    {
        if ($response->status() === 501) {
            throw new ContainerLifecycleDisabledException("Container lifecycle disabled for {$gezelId}");
        }
    }

    protected function middleware(): PendingRequest
    {
        return Http::baseUrl(config('gezel.middleware.url'))
            ->timeout(config('gezel.timeout'))
            ->withToken(config('gezel.middleware.app_token'));
    }
}
