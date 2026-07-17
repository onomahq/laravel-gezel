<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Onomahq\Gezel\Concerns\HasGezelAgent;
use Onomahq\Gezel\Contracts\GezelOwner;

class GezelUser extends Authenticatable implements GezelOwner
{
    use HasGezelAgent;

    protected $table = 'users';

    protected $guarded = [];
}
