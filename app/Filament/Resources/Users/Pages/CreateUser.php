<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Rail 1: a new user created with no group lands in the lowest one rather than
     * in role-less limbo. Only applied when nothing was picked, so an explicit
     * group choice is never overridden.
     */
    protected function afterCreate(): void
    {
        if ($this->record->roles()->doesntExist()) {
            $this->record->assignRole('viewer');
        }
    }
}
