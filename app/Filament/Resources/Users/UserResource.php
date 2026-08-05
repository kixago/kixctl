<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * User administration (RBAC-admin, Path A). Create people, place them in groups
 * (roles), and — where a group doesn't fit — grant an individual an exception on
 * top. Verb-level authorization only: this is who-can-do-what, never per-object
 * scoping (that multi-tenant axis is deliberately deferred; see roadmap-gaps.md).
 *
 * The whole resource is gated on `user.manage`, which is seeded but granted to no
 * role by default — super_admin reaches it through the Shield gate bypass, and the
 * permission stays available to hand to a trusted role later without code changes.
 * Because Filament hides a resource whose canViewAny() is false, the gate also
 * removes it from the navigation for anyone who can't manage users.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('common.labels.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('users.plural');
    }

    public static function getModelLabel(): string
    {
        return __('users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function canViewAny(): bool
    {
        return self::authorized();
    }

    public static function canCreate(): bool
    {
        return self::authorized();
    }

    public static function canView(Model $record): bool
    {
        return self::authorized();
    }

    public static function canEdit(Model $record): bool
    {
        return self::authorized();
    }

    public static function canDelete(Model $record): bool
    {
        return self::authorized();
    }

    public static function canDeleteAny(): bool
    {
        return self::authorized();
    }

    /** One gate for the whole resource: hold user.manage (super_admin bypasses). */
    private static function authorized(): bool
    {
        return Auth::user()?->can('user.manage') ?? false;
    }
}
