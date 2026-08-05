<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Rail 2 (delete): stop the last super_admin being deleted, with a
            // readable reason. The model-level deleting backstop still guards any
            // non-UI path.
            DeleteAction::make()
                ->before(function (DeleteAction $action): void {
                    if ($this->record->isLastSuperAdmin()) {
                        Notification::make()
                            ->danger()
                            ->title(__('users.guard.last_super_admin_title'))
                            ->body(__('users.guard.last_super_admin_delete'))
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    /**
     * Rail 2 (demotion): after the roles sync, if the panel now has zero
     * super_admins the edit must have stripped the last one — restore it to this
     * record and say so. A pure global count, so it can't be gamed by form state.
     * Demoting yourself while another super_admin exists is left alone.
     */
    protected function afterSave(): void
    {
        if (User::role('super_admin')->doesntExist()) {
            $this->record->assignRole('super_admin');

            Notification::make()
                ->danger()
                ->title(__('users.guard.last_super_admin_title'))
                ->body(__('users.guard.last_super_admin_edit'))
                ->send();
        }
    }
}
