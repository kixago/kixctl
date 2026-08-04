<?php

use App\Http\Controllers\PushDeployController;
use Illuminate\Support\Facades\Route;

// Machine-to-machine deploy trigger, one endpoint for every registered repo. On
// the stateless `api` group: no session, no CSRF — each request is authenticated
// by the per-repository HMAC signature inside the controller, not a logged-in
// user. The {host} segment selects the signature dialect only (Forgejo/Gitea/
// Codeberg raw-hex vs. GitHub "sha256="); the repository is resolved from the
// payload's full_name and verified against its own stored secret.
//
// Full paths: POST /api/deploy/forgejo (unchanged from P3-2), /api/deploy/github,
//             /api/deploy/gitea, /api/deploy/codeberg
Route::post('/deploy/{host}', PushDeployController::class)
    ->whereIn('host', ['forgejo', 'gitea', 'github', 'codeberg'])
    ->name('deploy.push');
