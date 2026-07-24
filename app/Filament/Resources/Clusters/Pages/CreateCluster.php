<?php

namespace App\Filament\Resources\Clusters\Pages;

use App\Filament\Resources\Clusters\ClusterResource;
use App\Services\Licensing\Entitlements;
use Filament\Resources\Pages\CreateRecord;

class CreateCluster extends CreateRecord
{
    protected static string $resource = ClusterResource::class;

    public bool $hasScopeIssues = false;

    public function mount(): void
    {
        parent::mount();

        $entitlements = app(Entitlements::class);

        if (! $entitlements->canAddCluster()) {
            ClusterResource::notifyClusterLimit($entitlements);
            $this->redirect(ClusterResource::getUrl('index'));
        }
    }

    protected function beforeCreate(): void
    {
        $entitlements = app(Entitlements::class);

        if ($entitlements->canAddCluster()) {
            return;
        }

        ClusterResource::notifyClusterLimit($entitlements);
        $this->halt();
    }

    protected function afterCreate(): void
    {
        $this->hasScopeIssues = ! ClusterResource::runScopeCheck($this->record);
    }

    protected function getRedirectUrl(): string
    {
        if ($this->hasScopeIssues) {
            // Stay on the edit form if there's a scope issue so they see the persistent notification
            return $this->getResource()::getUrl('edit', ['record' => $this->record]);
        }

        return $this->getResource()::getUrl('index');
    }
}
