<?php

use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\ContainerInfo;
use Onomahq\Gezel\Exceptions\ContainerLifecycleDisabledException;
use Onomahq\Gezel\GezelOrchestrator;
use Onomahq\Gezel\HealthStatus;

beforeEach(function () {
    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token-123');
});

it('provisions a container with the app_token and returns ContainerInfo', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/provision' => Http::response([
            'container_id' => 'c-abc',
            'status' => 'provisioned',
        ], 200),
    ]);

    $info = (new GezelOrchestrator)->provision('gezel-1', 'container-token');

    expect($info)->toBeInstanceOf(ContainerInfo::class);
    expect($info->containerId)->toBe('c-abc');
    expect($info->status)->toBe('provisioned');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://middleware.test/v1/containers/gezel-1/provision'
            && $request->hasHeader('Authorization', 'Bearer app-token-123')
            && $request['container_token'] === 'container-token';
    });
});

it('recreates a container', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/recreate' => Http::response([
            'container_id' => 'c-abc',
            'status' => 'provisioned',
        ], 200),
    ]);

    $info = (new GezelOrchestrator)->recreate('gezel-1', 'new-token');

    expect($info->containerId)->toBe('c-abc');
});

it('throws ContainerLifecycleDisabledException on a 501 response', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/provision' => Http::response('Docker not running', 501),
    ]);

    expect(fn () => (new GezelOrchestrator)->provision('gezel-1', 'token'))
        ->toThrow(ContainerLifecycleDisabledException::class);
});

it('throws a RuntimeException when provision fails without a container_id', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/provision' => Http::response(['error' => 'boom'], 500),
    ]);

    expect(fn () => (new GezelOrchestrator)->provision('gezel-1', 'token'))
        ->toThrow(RuntimeException::class);
});

it('deprovisions a container', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1' => Http::response([], 200),
    ]);

    expect((new GezelOrchestrator)->deprovision('gezel-1'))->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

it('restarts a container', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/restart' => Http::response([], 200),
    ]);

    expect((new GezelOrchestrator)->restart('gezel-1'))->toBeTrue();
});

it('reports healthy from a successful health check', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/health' => Http::response(['uptime_seconds' => 42], 200),
    ]);

    $status = (new GezelOrchestrator)->healthCheck('gezel-1');

    expect($status)->toBeInstanceOf(HealthStatus::class);
    expect($status->healthy)->toBeTrue();
    expect($status->uptimeSeconds)->toBe(42);
});

it('reports unhealthy without throwing when the health check fails', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/health' => Http::response([], 500),
    ]);

    $status = (new GezelOrchestrator)->healthCheck('gezel-1');

    expect($status->healthy)->toBeFalse();
});

it('writes config with the version and payload shape', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/config' => Http::response([], 200),
    ]);

    (new GezelOrchestrator)->writeConfig('gezel-1', ['persona' => 'default'], 3);

    Http::assertSent(fn ($request) => $request['version'] === 3 && $request['payload'] === ['persona' => 'default']);
});
