<?php

namespace App\Filament\Resources\Clusters\Pages;

use App\Filament\Resources\Clusters\ClusterResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCluster extends EditRecord
{
    protected static string $resource = ClusterResource::class;

    public bool $hasScopeIssues = false;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        // The dashboard aggregates every active cluster, so any number may be
        // active — nothing to reconcile here beyond the scope probe. A scope
        // issue keeps us on the page so the explanation stays visible.
        $this->hasScopeIssues = ! ClusterResource::runScopeCheck($this->record);
    }

    protected function getRedirectUrl(): ?string
    {
        // A clean save returns to the list; a scope issue stays here so the
        // persistent notification is seen (mirrors CreateCluster).
        if ($this->hasScopeIssues) {
            return null;
        }

        return $this->getResource()::getUrl('index');
    }
}
