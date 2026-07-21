<?php

namespace App\Models;

use App\Services\Incus\Cluster as ClusterEndpoint;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored connection record for one managed Incus cluster (kixctl's own state).
 * Distinct from App\Services\Incus\Cluster, which is the runtime value object the
 * rest of the app consumes — toEndpoint() maps this row to that.
 */
class Cluster extends Model
{
    protected $fillable = [
        'key', 'label', 'driver', 'url', 'socket',
        'client_cert', 'client_key', 'verify', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'client_cert' => 'encrypted', // AES-256 via APP_KEY, transparent on read
            'client_key' => 'encrypted',
            'verify' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Map this stored row to the runtime value object the service layer uses. */
    public function toEndpoint(): ClusterEndpoint
    {
        return new ClusterEndpoint(
            key: $this->key,
            label: $this->label,
            connection: [
                'driver' => $this->driver,
                'socket' => $this->socket,
                'url' => $this->url,
                'client_cert' => $this->client_cert, // PEM contents (cast decrypts on read)
                'client_key' => $this->client_key,  // PEM contents
                'verify' => $this->verify,
            ],
        );
    }
}
