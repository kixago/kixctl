<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

class ClusterInstances extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Cluster';

    protected static ?string $title = 'Cluster';

    protected string $view = 'filament.pages.cluster-instances';

    public array $clusters = [];

    public array $members = [];

    public array $instances = [];

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $incus = app(IncusClient::class);
        $registry = app(ClusterRegistry::class);

        $this->clusters = [];
        $this->members = [];
        $this->instances = [];

        foreach ($registry->all() as $cluster) {
            $this->clusters[] = ['key' => $cluster->key, 'label' => $cluster->label];
            $this->members = array_merge($this->members, $incus->members($cluster));
            $this->instances = array_merge($this->instances, $incus->instances($cluster));
        }

        $this->members = collect($this->members)->map(function ($m) {
            $parts = parse_url($m['url']);

            return [
                ...$m,
                'host' => $parts['host'] ?? $m['url'],
                'port' => isset($parts['port']) ? (string) $parts['port'] : '',
                'count' => collect($this->instances)
                    ->where('cluster', $m['cluster'])
                    ->where('node', $m['name'])
                    ->count(),
            ];
        })->values()->all();

        // Browser event → Alpine re-pulls the table with fresh live data.
        $this->dispatch('instance-changed');
    }

    /**
     * Whether the current user holds a given permission.
     * super_admin bypasses via Shield's gate interception.
     */
    protected function userCan(string $permission): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->can($permission) ?? false;
    }

    /** For the Blade: hide a lifecycle button ('start'|'stop'|'restart') per permission. */
    public function canRun(string $action): bool
    {
        return $this->userCan('instance.'.$action);
    }

    /** Invoked from the row buttons via $wire. Validates, acts, reloads. */
    public function runAction(string $cluster, string $name, string $action): void
    {
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            Notification::make()->title('Unsupported action')->danger()->send();

            return;
        }

        if (! $this->userCan('instance.'.$action)) {
            Notification::make()
                ->title('Not authorized')
                ->body("You do not have permission to {$action} instances.")
                ->danger()
                ->send();

            return;
        }

        $target = app(ClusterRegistry::class)->find($cluster);
        if (! $target) {
            Notification::make()->title('Unknown cluster')->danger()->send();

            return;
        }

        try {
            app(IncusClient::class)->setInstanceState($target, $name, $action);
            Notification::make()
                ->title(ucfirst($action).' succeeded')
                ->body($name)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(ucfirst($action).' failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->loadData(); // client pulls the fresh state after the call resolves
    }

    #[On('instance-created')]
    public function refreshInstances(): void
    {
        $this->loadData();
    }
}
