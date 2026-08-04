<?php

namespace App\Livewire;

use App\Models\Pool;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The Pools tab (P3-7). A pool groups apps that promote together, so one Update
 * cuts the whole batch over at once (D28). This is the management surface for the
 * pool as the first-class entity it is — create, relabel, delete, and see how many
 * apps are in each — the counterpart to the assign-a-repo dropdown on Repositories.
 *
 * Pure kixctl state, straight through the model, exactly like RepositoriesTable: a
 * pool is a database row with no cluster side effects. The name — the stable, unique
 * identifier — derives from the label on create and then stays put; editing changes
 * only the display label, so a pool's identity never shifts under it.
 *
 * Delete is guarded. With members still attached, the confirmation names them and
 * states plainly they are returned to promoting individually, never deleted: the
 * nullOnDelete FK does the un-pooling, the warning makes it a deliberate act. Empty,
 * it is a plain "this cannot be undone."
 */
class PoolsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query(Pool::query()->withCount('repositories')->orderBy('label'))
            ->columns([
                TextColumn::make('label')
                    ->label(__('pools.table.pool'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('name')
                    ->label(__('pools.table.name'))
                    ->color('gray'),
                TextColumn::make('repositories_count')
                    ->label(__('pools.table.apps'))
                    ->badge()
                    ->color(fn (Pool $record) => $record->repositories_count > 0 ? 'primary' : 'gray'),
            ])
            ->defaultSort('label')
            ->headerActions([
                Action::make('addPool')
                    ->label(__('pools.crud.add'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => Auth::user()?->can('pool.create') ?? false)
                    ->schema($this->formSchema())
                    ->action(function (array $data): void {
                        try {
                            Pool::create($this->normalize($data));
                            Notification::make()->title(__('pools.crud.added'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('pools.crud.add_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('editPool')
                    ->label(__('pools.crud.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn () => Auth::user()?->can('pool.update') ?? false)
                    ->fillForm(fn (Pool $record): array => [
                        'label' => $record->label,
                    ])
                    ->schema($this->formSchema())
                    ->action(function (Pool $record, array $data): void {
                        try {
                            $record->update($this->normalize($data));
                            Notification::make()->title(__('pools.crud.updated'))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('pools.crud.update_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('deletePool')
                    ->label(__('pools.crud.delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn () => Auth::user()?->can('pool.delete') ?? false)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Pool $record) => __('pools.crud.delete_heading', ['pool' => $record->label]))
                    ->modalDescription(fn (Pool $record) => $this->deleteDescription($record))
                    ->modalContent(fn (Pool $record) => $this->deleteMemberList($record))
                    ->action(function (Pool $record): void {
                        // nullOnDelete un-pools any members automatically; the apps are
                        // the cluster's and are left in place, still promoting — just
                        // individually again.
                        $record->delete();
                        Notification::make()->title(__('pools.crud.deleted'))->success()->send();
                    }),
            ]);
    }

    /**
     * The create/edit form. A pool needs only a display label; its stable name
     * derives from that label on create and never changes afterward, so editing
     * here touches the label alone.
     *
     * @return array<int, \Filament\Forms\Components\Field>
     */
    private function formSchema(): array
    {
        return [
            TextInput::make('label')
                ->label(__('pools.form.label'))
                ->required()
                ->placeholder(__('pools.form.label_placeholder'))
                ->helperText(__('pools.form.label_help')),
        ];
    }

    /**
     * The delete confirmation sentence: the consequence and the member count. The
     * app names themselves are rendered separately, as a scrollable list, so the
     * sentence stays short no matter how many apps are attached. Empty, it is a
     * plain irreversible-action notice.
     */
    private function deleteDescription(Pool $record): string
    {
        $count = $record->repositories()->count();

        if ($count === 0) {
            return __('pools.crud.delete_empty');
        }

        return trans_choice('pools.crud.delete_members', $count, ['count' => $count]);
    }

    /**
     * The attached apps as a height-capped, scrollable list in the delete modal
     * body, so a pool with three members and one with three hundred both read
     * cleanly — the box grows to its cap, then scrolls, rather than the warning
     * becoming a wall of names. An empty pool adds no content block (null).
     */
    private function deleteMemberList(Pool $record): ?View
    {
        $apps = $record->repositories()->orderBy('full_name')->pluck('full_name');

        if ($apps->isEmpty()) {
            return null;
        }

        return view('livewire.pools.delete-members', ['apps' => $apps]);
    }

    /**
     * Trim the submitted label so a stray space neither reaches the display name
     * nor skews the stable name the model derives from it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        if (array_key_exists('label', $data) && is_string($data['label'])) {
            $data['label'] = trim($data['label']);
        }

        return $data;
    }

    public function render()
    {
        return view('livewire.pools-table');
    }
}
