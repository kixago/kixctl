<?php

use Illuminate\Support\Facades\Route;

// The control panel lives at /admin. Send the bare root there for now; root is
// deliberately left reserved for a future pre-auth front door (first-run wizard,
// CA-trust download, status landing). Route::redirect is a controller action,
// not a closure, so route:cache stays valid.
Route::redirect('/', '/admin');
