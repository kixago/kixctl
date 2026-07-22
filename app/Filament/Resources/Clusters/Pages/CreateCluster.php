<?php

namespace App\Filament\Resources\Clusters\Pages;

use App\Filament\Resources\Clusters\ClusterResource;
use App\Services\Licensing\Entitlements;
use Filament\Resources\Pages\CreateRecord;

class CreateCluster extends CreateRecord
{
    protected static string $resource = ClusterResource::class;

    /**
     * Don't let someone fill a doomed form: direct navigation to /create
     * while at the cap bounces back to the list with the upsell. The
     * ListClusters button is the polish; this catches the URL path.
     */
    public function mount(): void
    {
        parent::mount();

        $entitlements = app(Entitlements::class);

        if (! $entitlements->canAddCluster()) {
            ClusterResource::notifyClusterLimit($entitlements);

            $this->redirect(ClusterResource::getUrl('index'));
        }
    }

    /**
     * Step 6 — the free-cap gate. Server-side, method-level (the same
     * pattern as the P2-R verb gates): the create is REFUSED here even if
     * a client re-enables the button or the count changed after mount.
     * halt() keeps the user on the form; nothing is saved.
     */
    protected function beforeCreate(): void
    {
        $entitlements = app(Entitlements::class);

        if ($entitlements->canAddCluster()) {
            return;
        }

        ClusterResource::notifyClusterLimit($entitlements);

        $this->halt();
    }

    /** A successful create returns to the cluster list, not the edit page. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
