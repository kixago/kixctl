<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One per-app config entry (an env var) injected into every immutable revision
 * of an app at launch. Declared once here — kixctl's own state, in Postgres —
 * and carried forward into each new revision, which is what makes an update feel
 * continuous even though the container is brand new.
 *
 * Values are encrypted at rest via APP_KEY (the same transparent cast the cluster
 * certs use). APP_KEY is itself a sops-nix secret in kixctl's own config, so
 * these sit encrypted under a sops-guarded key. Delivery into the container is a
 * separate hop (systemd credentials, set by the deploy job); this row is only
 * the at-rest home.
 */
class DeployAppConfig extends Model
{
    protected $table = 'deploy_app_config';

    protected $fillable = ['app', 'key', 'value', 'is_secret'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',   // AES-256 via APP_KEY, transparent on read
            'is_secret' => 'boolean',
        ];
    }
}
