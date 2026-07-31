<?php

namespace App\Livewire;

use App\Jobs\RunDeploymentAction;
use App\Models\AppRoute;
use App\Services\Deploy\DeploymentManager;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Updates tab: per-app deploy lifecycle, grouped by app. Reads the engine
 * (DeploymentManager::revisions) live from Incus — the display never diverges
 * from the cluster because there is no revision ledger to drift (decisions.md
 * D6). The operator acts from here:
 *
 *   - Cutover — promote a landed "update ready" revision to live.
 *   - Revert  — swing back to a prior, still-present revision.
 *   - Reap    — delete revisions retired past the window (dry-run confirm first).
 *
 * Cutover/revert run on the queue and stream a toast (RunDeploymentAction) so the
 * page never locks up, reusing the same Reverb rail as the network/ingress
 * provisioners. Reap is a fast set of deletes and runs inline. Standalone
 * Livewire component (a Page hosts only one table; those are Networks/Profiles),
 * so the proven tabs are untouched.
 */
class UpdatesTable extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** Non-empty while a cutover/revert is streaming to the toast. */
    public string $actionToken = '';

    public bool $running = false;

    /**
     * The grouped-by-app view model, built fresh from the engine each render.
     * Deploy-managed apps are the app_routes rows that name a live revision;
     * manual records (null live_instance) are not lifecycle-managed and skipped.
     * An app whose cluster is unreachable, or whose revisions have all been
     * reaped, simply doesn't appear rather than blanking the tab.
     *
     * @return list<array<string,mixed>>
     */
    public function apps(): array
    {
        $deployment = app(DeploymentManager::class);

        $appKeys = AppRoute::query()
            ->whereNotNull('live_instance')
            ->orderBy('app')
            ->pluck('app')
            ->all();

        $out = [];
        foreach ($appKeys as $app) {
            try {
                $state = $deployment->revisions($app);
            } catch (\Throwable) {
                continue;
            }

            if (empty($state['revisions'])) {
                continue;
            }

            $route = AppRoute::query()->where('app', $app)->first();

            $out[] = [
                'app' => $app,
                'host' => $route?->host,
                'live' => $state['live'],
                'update_ready' => $state['update_ready'],
                'revisions' => $state['revisions'],
                'reap_eligible' => collect($state['revisions'])->contains(fn ($r) => $r['reap_eligible']),
            ];
        }

        return $out;
    }

    public function cutoverAction(): Action
    {
        return Action::make('cutover')
            ->label(__('updates.action.cutover'))
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->requiresConfirmation()
            ->modalHeading(__('updates.action.cutover_heading'))
            ->modalDescription(fn (array $arguments) => __('updates.action.cutover_confirm', [
                'instance' => (string) ($arguments['instance'] ?? ''),
            ]))
            ->action(fn (array $arguments) => $this->dispatchAction(
                'cutover',
                (string) ($arguments['app'] ?? ''),
                (string) ($arguments['instance'] ?? ''),
            ));
    }

    public function revertAction(): Action
    {
        return Action::make('revert')
            ->label(__('updates.action.revert'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('updates.action.revert_heading'))
            ->modalDescription(fn (array $arguments) => __('updates.action.revert_confirm', [
                'instance' => (string) ($arguments['instance'] ?? ''),
            ]))
            ->action(fn (array $arguments) => $this->dispatchAction(
                'revert',
                (string) ($arguments['app'] ?? ''),
                (string) ($arguments['instance'] ?? ''),
            ));
    }

    public function reapAction(): Action
    {
        return Action::make('reap')
            ->label(__('updates.action.reap'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('updates.action.reap_heading'))
            ->modalDescription(function (array $arguments): string {
                $app = (string) ($arguments['app'] ?? '');

                try {
                    $plan = app(DeploymentManager::class)->reap($app, dryRun: true);
                } catch (\Throwable $e) {
                    return __('updates.action.reap_error', ['error' => $e->getMessage()]);
                }

                $list = $plan['reaped'] ? implode(', ', $plan['reaped']) : __('updates.none');

                return __('updates.action.reap_confirm', ['list' => $list]);
            })
            ->action(function (array $arguments): void {
                $app = (string) ($arguments['app'] ?? '');

                try {
                    $plan = app(DeploymentManager::class)->reap($app);
                    $reaped = $plan['reaped'] ? implode(', ', $plan['reaped']) : __('updates.none');

                    Notification::make()
                        ->title(__('updates.reaped'))
                        ->body($reaped)
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('updates.reap_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /** Fire a cutover/revert on the queue and flip the toast spinner on. */
    private function dispatchAction(string $action, string $app, string $instance): void
    {
        if ($app === '' || $instance === '') {
            return;
        }

        $this->actionToken = (string) Str::random(24);
        $this->running = true;

        RunDeploymentAction::dispatch($this->actionToken, Auth::id(), $action, $app, $instance);
    }

    /** The Alpine toast fires this on the terminal (done/failed) phase. */
    #[On('network-provisioned')]
    public function onDone(): void
    {
        $this->running = false;
    }

    /**
     * A push-triggered deploy reached a terminal phase (landed / published /
     * failed). The Alpine deploy watcher dispatches this once the build finishes;
     * the empty body is enough — handling a Livewire event re-runs render(), which
     * re-reads apps() so a freshly-landed revision's "ready to promote" banner
     * appears with no manual refresh. The in-flight building spinner itself is
     * Alpine-only, because a revision mid-build is not yet an Incus instance and so
     * cannot come from apps().
     */
    #[On('deploys-changed')]
    public function onDeploysChanged(): void
    {
        //
    }

    public function render()
    {
        return view('livewire.updates-table', ['apps' => $this->apps()]);
    }
}
