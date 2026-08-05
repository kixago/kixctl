<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('users.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('users.table.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label(__('users.table.groups'))
                    ->badge()
                    ->color('gray')
                    ->placeholder(__('users.table.no_group')),
                TextColumn::make('created_at')
                    ->label(__('users.table.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Rail 2 (bulk): refuse a selection that would leave zero
                    // super_admins. Checked before any row is deleted, so it's an
                    // all-or-nothing stop, not a partial wipe.
                    DeleteBulkAction::make()
                        ->before(function (Collection $records, DeleteBulkAction $action): void {
                            $remaining = User::role('super_admin')
                                ->whereNotIn('id', $records->modelKeys())
                                ->count();

                            if ($remaining === 0) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('users.guard.last_super_admin_title'))
                                    ->body(__('users.guard.last_super_admin_bulk'))
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }
}
