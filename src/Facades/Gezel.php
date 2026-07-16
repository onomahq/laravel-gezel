<?php

namespace Onomahq\Gezel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Onomahq\Gezel\Gezel
 */
class Gezel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Onomahq\Gezel\Gezel::class;
    }
}
