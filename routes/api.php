<?php

use App\Http\Controllers\ForgejoWebhookController;
use Illuminate\Support\Facades\Route;

// Machine-to-machine deploy trigger. On the stateless `api` group: no session,
// no CSRF token — the request is authenticated by the Forgejo HMAC signature
// inside the controller, not by a logged-in user.
//
// Full path: POST /api/deploy/forgejo
Route::post('/deploy/forgejo', ForgejoWebhookController::class)
    ->name('deploy.forgejo');
