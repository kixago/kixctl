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

        // At the cap: the button stays VISIBLE but locked — clicking it
        // upsells immediately instead of walking the user into a form
        // that can only be refused. (Paid-feature preview, not a hidden
        // feature.) Evaluated at page mount; freeing a slot shows the
        // real button on the next page load.
        return [
            Action::make('clusterLimit')
                ->label('New cluster')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('gray')
                ->action(fn () => ClusterResource::notifyClusterLimit($entitlements)),
        ];
    }
}
