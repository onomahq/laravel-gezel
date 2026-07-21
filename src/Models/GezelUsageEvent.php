<?php

namespace Onomahq\Gezel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Onomahq\Gezel\Support\Owner;

/**
 * One metered request, as delivered by the middleware's usage callback. Keyed
 * to the owner by gezel_id — the middleware-facing key — never the owner's
 * primary key, so a row survives owner deletion and can never point at a
 * recycled PK.
 */
class GezelUsageEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cache_creation_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'occurred_at' => 'datetime',
        'context' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::model(), 'gezel_id', 'gezel_id');
    }
}
