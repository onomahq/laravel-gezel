<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Onomahq\Gezel\Concerns\HasGezelAgent;
use Onomahq\Gezel\Contracts\GezelOwner;

class PassportOwner extends Authenticatable implements GezelOwner, OAuthenticatable
{
    use HasApiTokens;
    use HasGezelAgent;

    protected $table = 'users';

    protected $guarded = [];
}
