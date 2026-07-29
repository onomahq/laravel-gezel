<?php

namespace Onomahq\Gezel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\Exceptions\ContainerLifecycleDisabledException;
use RuntimeException;

class GezelOrchestrator
{
    public function provision(string $gezelId, string $containerToken): ContainerInfo
    {
        $response = $this->middleware()->post($this->containerPath($gezelId).'/provision', [
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
        $response = $this->middleware()->post($this->containerPath($gezelId).'/recreate', [
            'container_token' => $containerToken,
        ]);

        return $this->containerInfoFrom($response, $gezelId, 'recreate');
    }

    public function deprovision(string $gezelId): void
    {
        $response = $this->middleware()->delete($this->containerPath($gezelId));

        $this->guardLifecycleDisabled($response, $gezelId);

        $response->throw();
    }

    public function restart(string $gezelId): void
    {
        $response = $this->middleware()->post($this->containerPath($gezelId).'/restart');

        $this->guardLifecycleDisabled($response, $gezelId);

        $response->throw();
    }

    public function healthCheck(string $gezelId): HealthStatus
    {
        try {
            $response = $this->middleware()->get($this->containerPath($gezelId).'/health');
        } catch (ConnectionException $e) {
            return HealthStatus::unhealthy($e->getMessage());
        }

        if (! $response->successful()) {
            return HealthStatus::unhealthy("Health check returned {$response->status()}");
        }

        $uptime = $response->json('uptime_seconds');

        return HealthStatus::healthy(is_numeric($uptime) ? (int) $uptime : null);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function writeConfig(string $gezelId, array $config, int $version = 1): void
    {
        $response = $this->middleware()->post($this->containerPath($gezelId).'/config', [
            'version' => $version,
            'payload' => $config,
        ]);

        $this->guardLifecycleDisabled($response, $gezelId);

        $response->throw();
    }

    /**
     * The middleware's own view of an owner's month-to-date usage. It is the
     * cap authority, so this is the authoritative number: an app's ledger only
     * holds the events whose callbacks it received, and a dead-lettered
     * callback would make that ledger read low exactly when the cap bites.
     *
     * @param  string  $month  `YYYY-MM`
     * @return array<string, mixed>
     */
    public function usageStatus(string $gezelId, string $month): array
    {
        $response = $this->middleware()->get($this->containerPath($gezelId).'/usage/status', [
            'month' => $month,
        ]);

        $this->guardLifecycleDisabled($response, $gezelId);

        $response->throw();

        $status = $response->json();

        return is_array($status) ? $status : [];
    }

    protected function containerInfoFrom(Response $response, string $gezelId, string $action): ContainerInfo
    {
        $this->guardLifecycleDisabled($response, $gezelId);

        $response->throw();

        $containerId = $response->json('container_id');

        if (! is_string($containerId) || $containerId === '') {
            throw new RuntimeException("Gezel {$action} for {$gezelId} succeeded but returned no container_id.");
        }

        return new ContainerInfo(
            containerId: $containerId,
            status: (string) $response->json('status', 'provisioned'),
        );
    }

    protected function guardLifecycleDisabled(Response $response, string $gezelId): void
    {
        if ($response->status() === 501) {
            throw new ContainerLifecycleDisabledException("Container lifecycle disabled for {$gezelId}.");
        }
    }

    protected function containerPath(string $gezelId): string
    {
        return '/v1/containers/'.rawurlencode($gezelId);
    }

    protected function middleware(): PendingRequest
    {
        return Http::baseUrl(config('gezel.middleware.url'))
            ->timeout(config('gezel.timeout'))
            ->withToken(config('gezel.middleware.app_token'));
    }
}
