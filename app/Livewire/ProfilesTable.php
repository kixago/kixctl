<?php

namespace App\Livewire;

use App\Models\Network;
use App\Models\Profile;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use App\Services\Profiles\ProfileManager;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Livewire\Component;

/**
 * The Profiles tab table — the exact sibling of the Network tab table in
 * IngressSettings, but standalone. It has to be a separate Livewire component
 * because a Filament Page (IngressSettings) allows only ONE table() method, and
 * that one is already the Networks table. Keeping Profiles here means the proven
 * Network table is untouched, and a bad Filament idiom in Profiles is a
 * single-file revert.
 *
 * Every cluster mutation routes through ProfileManager, which is already proven
 * in isolation by the kixctl:profile-*-probe commands. The locked `kix` row is
 * guarded at the model layer (Profile::booted()); the UI simply hides Edit/Delete
 * on it, exactly as the Network tab hides them on the locked kixbr0.
 */
class ProfilesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** Per-request memo of live Incus profile state (not Livewire-tracked). */
    private ?array $liveProfilesCache = null;

    /** Per-request memo of single-profile detail fetches, keyed by name. */
    private array $profileDetailCache = [];

    public function table(Table $table): Table
    {
        return $table
            ->query(Profile::query()->orderBy('sort')->orderBy('id'))
            ->columns([
                TextColumn::make('key')
                    ->label(__('profiles.table.key'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('label')
                    ->label(__('profiles.table.label')),
                TextColumn::make('pool')
                    ->label(__('profiles.table.pool'))
                    ->state(fn (Profile $record) => $record->managed
                        ? ($record->pool ?: __('profiles.table.auto'))
                        : ($this->liveProfiles()[$record->key]['root_pool'] ?? __('profiles.table.none')))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('devices')
                    ->label(__('profiles.table.devices'))
                    ->state(fn (Profile $record) => implode(', ', $this->liveProfiles()[$record->key]['devices'] ?? [])
                        ?: __('profiles.table.none'))
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_default')
                    ->label(__('profiles.table.default'))
                    ->boolean(),
                TextColumn::make('used_by')
                    ->label(__('profiles.table.used_by'))
                    ->state(fn (Profile $record) => $this->liveProfiles()[$record->key]['used_by'] ?? __('profiles.table.none'))
                    ->badge()
                    ->color('gray'),
                IconColumn::make('managed')
                    ->label(__('profiles.table.managed'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_locked')
                    ->label(__('profiles.table.locked'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->headerActions([
                Action::make('createProfile')
                    ->label(__('profiles.crud.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->schema([
                        TextInput::make('key')
                            ->label(__('profiles.form.key'))
                            ->required()
                            ->alphaDash()
                            ->helperText(__('profiles.form.key_help')),
                        TextInput::make('label')
                            ->label(__('profiles.form.label'))
                            ->required(),
                        Textarea::make('description')
                            ->label(__('profiles.form.description'))
                            ->rows(2)
                            ->helperText(__('profiles.form.description_help')),
                        Select::make('pool')
                            ->label(__('profiles.form.pool'))
                            ->options(fn () => $this->poolOptions())
                            ->placeholder(__('profiles.form.pool_placeholder'))
                            ->helperText(__('profiles.form.pool_help')),
                        Select::make('nic_network')
                            ->label(__('profiles.form.nic'))
                            ->options(fn () => $this->networkOptions())
                            ->placeholder(__('profiles.form.nic_placeholder'))
                            ->helperText(__('profiles.form.nic_help')),
                        Toggle::make('is_default')
                            ->label(__('profiles.form.is_default'))
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(ProfileManager::class)->create($data);
                            Notification::make()->title(__('profiles.crud.created'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('profiles.crud.create_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('registerProfile')
                    ->label(__('profiles.crud.register'))
                    ->icon(Heroicon::OutlinedLink)
                    ->color('gray')
                    ->schema([
                        Select::make('key')
                            ->label(__('profiles.form.existing'))
                            ->options(fn () => $this->registerableProfiles())
                            ->required()
                            ->helperText(__('profiles.form.existing_help')),
                        TextInput::make('label')
                            ->label(__('profiles.form.label'))
                            ->required(),
                        Textarea::make('description')
                            ->label(__('profiles.form.description'))
                            ->rows(2)
                            ->helperText(__('profiles.form.description_help')),
                        Toggle::make('is_default')
                            ->label(__('profiles.form.is_default'))
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(ProfileManager::class)->register($data);
                            Notification::make()->title(__('profiles.crud.registered'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('profiles.crud.register_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('profilesToDefaults')
                    ->label(__('profiles.crud.defaults'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn () => $this->canResetProfiles())
                    ->requiresConfirmation()
                    ->modalDescription(__('profiles.crud.defaults_confirm'))
                    ->action(function (): void {
                        $result = app(ProfileManager::class)->backToDefaults();

                        $removed = $result['removed'] ? implode(', ', $result['removed']) : __('profiles.table.none');
                        $body = __('profiles.crud.defaults_done', ['removed' => $removed]);
                        if (! empty($result['skipped'])) {
                            $body .= ' '.__('profiles.crud.defaults_skipped', ['skipped' => implode('; ', $result['skipped'])]);
                        }

                        Notification::make()
                            ->title(__('profiles.crud.defaults_title'))
                            ->body($body)
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('editProfile')
                    ->label(__('profiles.crud.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (Profile $record) => ! $record->is_locked)
                    ->fillForm(function (Profile $record): array {
                        $detail = $this->profileDetail($record->key);
                        $unmanaged = ! $record->managed;

                        return [
                            'key' => $record->key,
                            'label' => $record->label,
                            'description' => $record->description,
                            // Managed: the row is the source of truth for the pool
                            // (null = auto). Unmanaged: show the live root pool.
                            'pool' => $unmanaged
                                ? ($detail['root_pool'] ?? '')
                                : ($record->pool ?? ''),
                            'nic_network' => $detail['nic_network'] ?? null,
                        ];
                    })
                    ->schema(function (Profile $record): array {
                        $unmanaged = ! $record->managed;

                        $fields = [
                            TextInput::make('key')
                                ->label(__('profiles.form.key'))
                                ->disabled()
                                ->helperText(__('profiles.form.key_locked_help')),
                            TextInput::make('label')
                                ->label(__('profiles.form.label'))
                                ->required(),
                            Textarea::make('description')
                                ->label(__('profiles.form.description'))
                                ->rows(2)
                                ->helperText(__('profiles.form.description_help')),
                            Select::make('pool')
                                ->label(__('profiles.form.pool'))
                                ->options(fn () => $this->poolOptions())
                                ->placeholder(__('profiles.form.pool_placeholder'))
                                ->disabled($unmanaged)
                                ->helperText($unmanaged
                                    ? __('profiles.form.pool_unmanaged_help')
                                    : __('profiles.form.pool_locked_help')),
                        ];

                        // A NIC on the profile is only kixctl's to manage when the
                        // profile is kixctl-managed. Unmanaged rows never offer it.
                        if (! $unmanaged) {
                            $fields[] = Select::make('nic_network')
                                ->label(__('profiles.form.nic'))
                                ->options(fn () => $this->networkOptions())
                                ->placeholder(__('profiles.form.nic_placeholder'))
                                ->helperText(__('profiles.form.nic_help'));
                        }

                        return $fields;
                    })
                    ->action(function (Profile $record, array $data): void {
                        try {
                            app(ProfileManager::class)->update($record, $data);
                            Notification::make()->title(__('profiles.crud.updated'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('profiles.crud.update_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('setDefault')
                    ->label(__('profiles.crud.set_default'))
                    ->icon(Heroicon::OutlinedStar)
                    ->visible(fn (Profile $record) => ! $record->is_default)
                    ->action(function (Profile $record): void {
                        app(ProfileManager::class)->setDefault($record);
                        Notification::make()->title(__('profiles.crud.default_set'))->success()->send();
                    }),
                Action::make('deleteProfile')
                    ->label(fn (Profile $record) => $record->managed ? __('profiles.crud.delete') : __('profiles.crud.deregister'))
                    ->icon(fn (Profile $record) => $record->managed ? Heroicon::OutlinedTrash : Heroicon::OutlinedLinkSlash)
                    ->color(fn (Profile $record) => $record->managed ? 'danger' : 'gray')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Profile $record) => $record->managed
                        ? __('profiles.crud.delete_confirm')
                        : __('profiles.crud.deregister_confirm', ['key' => $record->key]))
                    ->visible(fn (Profile $record) => ! $record->is_locked)
                    ->action(function (Profile $record): void {
                        $wasManaged = $record->managed;
                        try {
                            app(ProfileManager::class)->delete($record);
                            Notification::make()
                                ->title($wasManaged ? __('profiles.crud.deleted') : __('profiles.crud.deregistered'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title($wasManaged ? __('profiles.crud.delete_failed') : __('profiles.crud.deregister_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    /**
     * Live Incus profile state, keyed by name, fetched ONCE per request and
     * memoized. Uses the bulk recursion=1 list (profilesFull) for used_by + the
     * device-key list, plus a cheap per-row root-pool derived only for unmanaged
     * rows in the table. Degrades to an empty map if the cluster is unreachable.
     *
     * @return array<string,array<string,mixed>>
     */
    private function liveProfiles(): array
    {
        if ($this->liveProfilesCache !== null) {
            return $this->liveProfilesCache;
        }

        try {
            $incus = app(IncusClient::class);
            $cluster = collect(app(ClusterRegistry::class)->all())->first();

            $map = [];
            if ($cluster) {
                foreach ($incus->profilesFull($cluster) as $p) {
                    $map[$p['name']] = [
                        'used_by' => $p['used_by'] ?? 0,
                        'devices' => $p['devices'] ?? [],
                        'root_pool' => null, // filled lazily below only where shown
                    ];
                }

                // The bulk list doesn't carry the root-disk pool; fill it in only
                // for UNMANAGED rows (managed rows show the row's own pool value),
                // and only for rows that actually exist, so this stays cheap.
                foreach (Profile::query()->where('managed', false)->pluck('key') as $key) {
                    if (isset($map[$key])) {
                        $map[$key]['root_pool'] = $this->profileDetail($key)['root_pool'] ?? null;
                    }
                }
            }

            return $this->liveProfilesCache = $map;
        } catch (\Throwable) {
            return $this->liveProfilesCache = [];
        }
    }

    /**
     * Single-profile detail (root-disk pool + eth0 network), memoized per key.
     * Used by the edit form and by the unmanaged-row pool column.
     *
     * @return array{root_pool:?string, nic_network:?string}
     */
    private function profileDetail(string $key): array
    {
        if (array_key_exists($key, $this->profileDetailCache)) {
            return $this->profileDetailCache[$key];
        }

        $detail = ['root_pool' => null, 'nic_network' => null];

        try {
            $incus = app(IncusClient::class);
            $cluster = collect(app(ClusterRegistry::class)->all())->first();
            if ($cluster && $incus->profileExists($cluster, $key)) {
                $live = $incus->profile($cluster, $key);
                $devices = (array) ($live['devices'] ?? []);
                $detail['root_pool'] = $devices['root']['pool'] ?? null;
                $detail['nic_network'] = $devices['eth0']['network'] ?? null;
            }
        } catch (\Throwable) {
            // leave nulls; the form/column degrade gracefully
        }

        return $this->profileDetailCache[$key] = $detail;
    }

    /**
     * Network keys offered for an eth0 NIC on a managed profile — the same rows
     * the Network tab manages. Blank (no selection) means a root-disk-only
     * profile, which is the norm (placement is per-instance).
     *
     * @return array<string,string>
     */
    private function networkOptions(): array
    {
        return Network::query()
            ->orderBy('sort')->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Network $n) => [$n->key => $n->key.' — '.$n->label])
            ->all();
    }

    /**
     * Storage pools on the cluster, for pinning a root-disk pool. Blank = auto
     * (kixctl resolves one). Degrades to an empty list (still allows blank/auto)
     * if the cluster is unreachable.
     *
     * @return array<string,string>
     */
    private function poolOptions(): array
    {
        try {
            $incus = app(IncusClient::class);
            $cluster = collect(app(ClusterRegistry::class)->all())->first();
            if (! $cluster) {
                return [];
            }

            $out = [];
            foreach ($incus->storagePools($cluster) as $p) {
                $driver = $p['driver'] ?? '';
                $out[$p['name']] = $p['name'].($driver !== '' ? " ({$driver})" : '');
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Whether a profile reset is meaningful right now: there's a kixctl-created
     * extra to remove, or the default has been moved off the locked kix.
     */
    private function canResetProfiles(): bool
    {
        $hasExtras = Profile::query()->where('is_locked', false)->where('managed', true)->exists();
        $fallback = Profile::fallback();
        $defaultMoved = $fallback && ! $fallback->is_default;

        return $hasExtras || $defaultMoved;
    }

    /**
     * Existing Incus profiles not yet referenced by a kixctl row — the pickable
     * targets for "Register existing". kixctl-managed rows are already tracked,
     * so they're excluded.
     *
     * @return array<string,string>
     */
    private function registerableProfiles(): array
    {
        $taken = Profile::query()->pluck('key')->all();

        $out = [];
        try {
            $incus = app(IncusClient::class);
            $cluster = collect(app(ClusterRegistry::class)->all())->first();
            if ($cluster) {
                foreach ($incus->profilesFull($cluster) as $p) {
                    $name = $p['name'];
                    if (in_array($name, $taken, true)) {
                        continue; // already referenced or managed by kixctl
                    }
                    $out[$name] = $name.' ('.($p['used_by'] ?? 0).' in use)';
                }
            }
        } catch (\Throwable) {
            // cluster unreachable — nothing to offer
        }

        return $out;
    }

    public function render()
    {
        return view('livewire.profiles-table');
    }
}
