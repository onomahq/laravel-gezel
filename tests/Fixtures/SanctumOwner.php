<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Onomahq\Gezel\Concerns\HasGezelAgent;

class SanctumOwner extends Authenticatable
{
    use HasApiTokens;
    use HasGezelAgent;

    protected $table = 'sanctum_owners';

    protected $guarded = [];
}
