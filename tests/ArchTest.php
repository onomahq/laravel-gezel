<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

/**
 * The package resolves a bearer to a principal and attaches it to request
 * attributes. Binding that principal to an authenticated user is the host
 * app's call, never the package's: Onoma's tenancy (the BelongsToCurrentUser
 * global scope, OwnedByUserPolicy) keys on Auth::user(), and a package that
 * bound it would be deciding an app's tenancy model from the outside.
 * Stagent's owner is a Team, which cannot authenticate at all.
 *
 * A test rather than a docblock because the failure is silent: an
 * Auth::setUser() added here would work fine in Onoma and quietly cross
 * tenants in the next app that mounts the package.
 */
arch('the package never binds the authenticated user')
    ->expect('Onomahq\Gezel')
    ->not->toUse([
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Contracts\Auth\Guard',
    ]);
