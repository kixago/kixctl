<?php

namespace App\Livewire;

use App\Models\Repository;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The Repositories tab (P3-6). A standalone Livewire component for the same
 * reason Profiles / Records / Updates are: a Filament Page allows one table() and
 * IngressSettings already spends it on Networks. Keeping this separate means the
 * proven surfaces are untouched and a bad idiom here is a single-file revert.
 *
 * A Repository is pure kixctl state — no cluster mutation — so create / edit /
 * delete go straight through the model (the slug and host derive themselves on
 * save), unlike the network/profile tabs which route through a probe-proven
 * manager because they change the live cluster. "Deploy now" reuses the exact
 * poller path (kixctl:poll-repositories --repo … --force) on the queue, so a
 * button press and a scheduled poll are the same operation.
 */
class RepositoriesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query(Repository::query()->orderBy('full_name'))
            ->columns([
                TextColumn::make('full_name')
                    ->label(__('repositories.table.repository'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('host')
                    ->label(__('repositories.table.host'))
                    ->placeholder('—'),
                TextColumn::make('default_branch')
                    ->label(__('repositories.table.branch'))
                    ->state(fn (Repository $record) => $record->default_branch !== null && $record->default_branch !== ''
                        ? $record->default_branch
                        : __('repositories.table.branch_head')),
                TextColumn::make('build_attr')
                    ->label(__('repositories.table.build_attr'))
                    ->state(fn (Repository $record) => $record->buildAttr())
                    ->badge()
                    ->color('gray'),
                IconColumn::make('poll_effective')
                    ->label(__('repositories.table.poll'))
                    ->boolean()
                    ->state(fn (Repository $record) => $record->poll_enabled && $record->is_active)
                    ->tooltip(fn (Repository $record) => $record->poll_enabled && ! $record->is_active
                        ? __('repositories.table.poll_inactive_tip')
                        : null),
                TextColumn::make('last_polled_at')
                    ->label(__('repositories.table.last_poll'))
                    ->state(fn (Repository $record) => $record->last_poll_error
                        ? __('repositories.table.poll_error')
                        : ($record->last_polled_at?->diffForHumans() ?? __('repositories.table.never')))
                    ->badge()
                    ->color(fn (Repository $record) => $record->last_poll_error ? 'danger' : 'gray')
                    ->tooltip(fn (Repository $record) => $record->last_poll_error
                        ?: ($record->last_seen_sha ? substr($record->last_seen_sha, 0, 7) : null)),
                IconColumn::make('has_webhook')
                    ->label(__('repositories.table.webhook'))
                    ->state(fn (Repository $record) => $record->hasWebhook())
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label(__('repositories.table.active'))
                    ->boolean(),
            ])
            ->defaultSort('full_name')
            ->poll('30s')
            ->headerActions([
                Action::make('addRepository')
                    ->label(__('repositories.crud.add'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => Auth::user()?->can('repository.create') ?? false)
                    ->schema($this->formSchema())
                    ->action(function (array $data): void {
                        try {
                            Repository::create($this->normalize($data));
                            Notification::make()->title(__('repositories.crud.added'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('repositories.crud.add_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('deployNow')
                    ->label(__('repositories.crud.deploy'))
                    ->icon(Heroicon::OutlinedRocketLaunch)
                    ->visible(fn () => Auth::user()?->can('repository.deploy') ?? false)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Repository $record) => __('repositories.crud.deploy_confirm', ['repo' => $record->full_name]))
                    ->action(function (Repository $record): void {
                        // The same poller path, forced for one repo, on the queue —
                        // it resolves the tip commit and deploys it if new. Progress
                        // then surfaces on the Updates tab like any other deploy.
                        Artisan::queue('kixctl:poll-repositories', [
                            '--repo' => $record->full_name,
                            '--force' => true,
                        ]);

                        Notification::make()
                            ->title(__('repositories.crud.deploy_queued', ['repo' => $record->full_name]))
                            ->success()
                            ->send();
                    }),
                Action::make('editRepository')
                    ->label(__('repositories.crud.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn () => Auth::user()?->can('repository.update') ?? false)
                    ->fillForm(fn (Repository $record): array => [
                        'full_name' => $record->full_name,
                        'slug' => $record->slug,
                        'clone_url' => $record->clone_url,
                        'default_branch' => $record->default_branch,
                        'build_attr' => $record->build_attr,
                        'webhook_secret' => $record->webhook_secret,
                        'poll_enabled' => (bool) $record->poll_enabled,
                        'poll_interval' => (int) $record->poll_interval,
                        'is_active' => (bool) $record->is_active,
                    ])
                    ->schema($this->formSchema(editing: true))
                    ->action(function (Repository $record, array $data): void {
                        try {
                            $record->update($this->normalize($data));
                            Notification::make()->title(__('repositories.crud.updated'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('repositories.crud.update_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('deleteRepository')
                    ->label(__('repositories.crud.delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn () => Auth::user()?->can('repository.delete') ?? false)
                    ->requiresConfirmation()
                    ->modalDescription(__('repositories.crud.delete_confirm'))
                    ->action(function (Repository $record): void {
                        // Removes the registration and its config only. Running
                        // revisions and routes are the cluster's; they are untouched.
                        $record->delete();
                        Notification::make()->title(__('repositories.crud.deleted'))->success()->send();
                    }),
            ]);
    }

    /**
     * The add/edit form. slug and the several optional fields are laid out so the
     * "just point it at my repo" path is two fields; the rest have safe defaults.
     *
     * @return array<int, \Filament\Forms\Components\Field>
     */
    private function formSchema(bool $editing = false): array
    {
        return [
            TextInput::make('full_name')
                ->label(__('repositories.form.full_name'))
                ->required()
                ->placeholder(__('repositories.form.full_name_placeholder'))
                ->helperText(__('repositories.form.full_name_help')),
            TextInput::make('clone_url')
                ->label(__('repositories.form.clone_url'))
                ->required()
                ->placeholder(__('repositories.form.clone_url_placeholder'))
                ->helperText(__('repositories.form.clone_url_help')),
            TextInput::make('slug')
                ->label(__('repositories.form.slug'))
                ->alphaDash()
                ->placeholder(__('repositories.form.slug_placeholder'))
                ->helperText(__('repositories.form.slug_help')),
            TextInput::make('default_branch')
                ->label(__('repositories.form.branch'))
                ->placeholder(__('repositories.form.branch_placeholder'))
                ->helperText(__('repositories.form.branch_help')),
            TextInput::make('build_attr')
                ->label(__('repositories.form.build_attr'))
                ->placeholder((string) config('deploy.build.attr', 'default'))
                ->helperText(__('repositories.form.build_attr_help')),
            TextInput::make('webhook_secret')
                ->label(__('repositories.form.webhook_secret'))
                ->password()
                ->revealable()
                ->helperText(__('repositories.form.webhook_secret_help')),
            Toggle::make('poll_enabled')
                ->label(__('repositories.form.poll_enabled'))
                ->default(true)
                ->helperText(__('repositories.form.poll_enabled_help')),
            TextInput::make('poll_interval')
                ->label(__('repositories.form.poll_interval'))
                ->numeric()
                ->minValue(10)
                ->default(60)
                ->helperText(__('repositories.form.poll_interval_help')),
            Toggle::make('is_active')
                ->label(__('repositories.form.is_active'))
                ->default(true)
                ->visible($editing)
                ->helperText(__('repositories.form.is_active_help')),
        ];
    }

    /**
     * Coerce the submitted form into model-ready values: blank optionals become
     * null (so the model's fallbacks apply), and a blank secret on edit is left
     * untouched rather than wiped.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        foreach (['slug', 'default_branch', 'build_attr', 'webhook_secret'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === '') {
                $data[$key] = null;
            }
        }

        // A blank slug is fine — the model derives it on save.
        if (array_key_exists('poll_interval', $data)) {
            $data['poll_interval'] = max(10, (int) $data['poll_interval']);
        }

        return $data;
    }

    public function render()
    {
        return view('livewire.repositories-table');
    }
}
