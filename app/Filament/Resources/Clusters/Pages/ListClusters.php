<?php

namespace App\Filament\Resources\Clusters\Pages;

use App\Filament\Resources\Clusters\ClusterResource;
use App\Services\Licensing\Entitlements;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListClusters extends ListRecords
{
    protected static string $resource = ClusterResource::class;

    protected function getHeaderActions(): array
    {
        $entitlements = app(Entitlements::class);

        if ($entitlements->canAddCluster()) {
            return [
                CreateAction::make(),
            ];
        }

        return [
            Action::make('clusterLimit')
                ->label(__('licensing.gate.button_locked'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('gray')
                ->action(fn () => ClusterResource::notifyClusterLimit($entitlements)),
        ];
    }
}
