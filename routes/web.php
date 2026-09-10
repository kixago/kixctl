<?php

use App\Livewire\SetupWizard;
use Illuminate\Support\Facades\Route;

// The first-run wizard owns the bare root as a pre-auth front door. It gates on
// InstanceSetting.configured_at: an unconfigured appliance renders the wizard, a
// configured one redirects to /admin (handled in SetupWizard::mount). Mounting a
// class-based Livewire component — not a closure — keeps route:cache valid.
Route::get('/', SetupWizard::class)->name('setup');
