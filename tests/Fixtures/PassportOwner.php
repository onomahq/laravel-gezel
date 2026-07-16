<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Onomahq\Gezel\Concerns\HasGezelAgent;

class PassportOwner extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;
    use HasGezelAgent;

    protected $table = 'passport_owners';

    protected $guarded = [];
}
