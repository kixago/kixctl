<?php

namespace App\Livewire;

use App\Models\InstanceSetting;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The pre-auth first-run wizard, mounted at the bare root. This is the app's only
 * full-page, non-Filament Livewire component — every other one is embedded in the
 * Filament panel at /admin. 3a is the shell and the gate: an unconfigured
 * appliance shows the wizard, a configured one is handed off to /admin. The steps
 * (domain, admin password, and the start-fresh / bolt-on Incus branch) plus the
 * live kixbr0 + CoreDNS stand-up arrive in 3b/3c.
 */
#[Layout('components.layouts.wizard')]
class SetupWizard extends Component
{
    public function mount(): void
    {
        // Already configured? The wizard has done its job — send them to the panel.
        if (InstanceSetting::current()->isConfigured()) {
            $this->redirect('/admin');
        }
    }

    public function render()
    {
        return view('livewire.setup-wizard');
    }
}
