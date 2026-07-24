<?php

namespace App\Filament\Resources\Clusters\Pages;

use App\Filament\Resources\Clusters\ClusterResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCluster extends EditRecord
{
    protected static string $resource = ClusterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        ClusterResource::runScopeCheck($this->record);
    }

    protected function getRedirectUrl(): ?string
    {
        // Null keeps them on the edit page so the persistent notification is visible
        return null;
    }
}
