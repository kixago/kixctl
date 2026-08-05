<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('users.form.section_identity'))
                    ->description(__('users.form.section_identity_help'))
                    ->components([
                        TextInput::make('name')
                            ->label(__('users.form.name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label(__('users.form.email'))
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        // Required on create; on edit, blank means "keep current".
                        // Blanked on hydrate so an untouched edit never re-hashes the
                        // stored hash, and dehydrated only when actually filled.
                        TextInput::make('password')
                            ->label(__('users.form.password'))
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->afterStateHydrated(fn (TextInput $component) => $component->state(''))
                            ->helperText(__('users.form.password_help')),
                    ]),

                Section::make(__('users.form.section_groups'))
                    ->description(__('users.form.section_groups_help'))
                    ->components([
                        Select::make('roles')
                            ->label(__('users.form.groups'))
                            ->relationship('roles', 'name', fn (Builder $query) => $query->where('guard_name', 'web'))
                            ->multiple()
                            ->preload()
                            ->helperText(__('users.form.groups_help')),
                    ]),

                Section::make(__('users.form.section_direct'))
                    ->description(__('users.form.section_direct_help'))
                    ->components([
                        Select::make('permissions')
                            ->label(__('users.form.direct_permissions'))
                            ->relationship('permissions', 'name', fn (Builder $query) => $query->where('guard_name', 'web'))
                            ->multiple()
                            ->preload()
                            ->helperText(__('users.form.direct_permissions_help')),
                    ]),
            ]);
    }
}
