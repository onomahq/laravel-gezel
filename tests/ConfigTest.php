<?php

it('ships the contract values the middleware relies on', function () {
    $config = require __DIR__.'/../config/gezel.php';

    expect($config['middleware']['url'])->toBe('http://localhost:8800');
    expect($config['timeout'])->toBe(120);
    expect($config['routes']['prefix'])->toBe('api/v1/internal');
    expect($config['owner']['acknowledges_shared_memory'])->toBeFalse();
});

it('auto-merges the package config into the application under the gezel key', function () {
    expect(config('gezel.middleware.url'))->toBe('http://localhost:8800');
    expect(config('gezel.timeout'))->toBe(120);
    expect(config('gezel.routes.prefix'))->toBe('api/v1/internal');
});
