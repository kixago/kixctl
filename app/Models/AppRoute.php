<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The live revision (and its address) for one app. The ingress providers render
 * the full set of these into the zone. A deploy upserts the row; a future
 * cutover/revert just points `live_instance` at a different revision and asks
 * the provider to re-publish.
 */
class AppRoute extends Model
{
    protected $fillable = [
        'app', 'host', 'live_instance', 'ip', 'port',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
        ];
    }
}
