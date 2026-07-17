<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Concerns\HasGezelAgent;
use Onomahq\Gezel\Contracts\GezelOwner;

class GezelTeam extends Model implements GezelOwner
{
    use HasGezelAgent;

    protected $table = 'gezel_teams';

    protected $guarded = [];
}
