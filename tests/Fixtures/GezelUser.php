<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Onomahq\Gezel\Concerns\HasGezelAgent;

class GezelUser extends Authenticatable
{
    use HasGezelAgent;

    protected $table = 'users';

    protected $guarded = [];
}
