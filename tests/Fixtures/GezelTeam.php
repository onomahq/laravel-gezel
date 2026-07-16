<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Concerns\HasGezelAgent;

class GezelTeam extends Model
{
    use HasGezelAgent;

    protected $table = 'gezel_teams';

    protected $guarded = [];
}
