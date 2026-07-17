<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Onomahq\Gezel\Concerns\DeprovisionsGezelContainer;
use Onomahq\Gezel\Concerns\HasGezelAgent;

class DeprovisioningOwner extends Authenticatable
{
    use DeprovisionsGezelContainer;
    use HasApiTokens;
    use HasGezelAgent;

    protected $table = 'users';

    protected $guarded = [];
}
