<?php

namespace App\Filament\Resources\Clusters\Tables;

use App\Models\Cluster;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ClustersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label(__('clusters.table.label'))
                    ->searchable(),
                TextColumn::make('key')
                    ->label(__('clusters.table.key'))
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('driver')
                    ->label(__('clusters.table.driver'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('url')
                    ->label(__('clusters.table.endpoint'))
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label(__('clusters.table.active'))
                    ->boolean()
                    // Click to toggle whether this cluster shows on the dashboard.
                    // The dashboard aggregates every active cluster, so any number
                    // may be on — this is an independent on/off, not a radio pick.
                    ->tooltip(fn (Cluster $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->action(function (Cluster $record): void {
                        $record->update(['is_active' => ! $record->is_active]);

                        Notification::make()
                            ->title(($record->is_active ? 'Activated ' : 'Deactivated ').$record->label)
                            ->success()
                            ->send();
                    }),
                IconColumn::make('verify')
                    ->label(__('clusters.table.tls_verify'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sort')
                    ->label(__('clusters.table.sort'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('clusters.table.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort')
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->label('Activate')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->action(fn (Collection $records) => Cluster::query()->whereKey($records->modelKeys())->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->label('Deactivate')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('gray')
                        ->action(fn (Collection $records) => Cluster::query()->whereKey($records->modelKeys())->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
