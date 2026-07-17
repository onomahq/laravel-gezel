<?php

use Onomahq\Gezel\Support\Viewing;

it('builds from an array with only the required fields', function () {
    $viewing = Viewing::fromArray(['kind' => 'page', 'name' => 'Dashboard']);

    expect($viewing->kind)->toBe('page');
    expect($viewing->name)->toBe('Dashboard');
    expect($viewing->id)->toBeNull();
    expect($viewing->detail)->toBeNull();
});

it('builds from an array with every field', function () {
    $viewing = Viewing::fromArray([
        'kind' => 'event',
        'name' => 'Launch Party',
        'id' => 'evt-1',
        'detail' => '2026-08-01, Venue X',
    ]);

    expect($viewing->id)->toBe('evt-1');
    expect($viewing->detail)->toBe('2026-08-01, Venue X');
});
