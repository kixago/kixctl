<?php

namespace App\Filament\Resources\Clusters\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                    ->boolean(),
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
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
