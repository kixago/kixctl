<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Only users with a recognized role may enter the admin panel.
     * super_admin bypasses per-action gates elsewhere; operator is
     * seeded next. Real enforcement lives at the action/method level.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(['super_admin', 'operator']);
    }

    /**
     * The one governance invariant: at least one super_admin must always exist.
     * True only when this user holds super_admin AND no other user does — i.e.
     * removing or demoting this user would orphan the panel. Deliberately a live
     * count, not a cached flag, so it can't drift.
     */
    public function isLastSuperAdmin(): bool
    {
        if (! $this->hasRole('super_admin')) {
            return false;
        }

        return static::role('super_admin')->whereKeyNot($this->getKey())->doesntExist();
    }

    /**
     * Hard backstop for the invariant on every delete path — Filament single and
     * bulk actions guard it first with a friendly notice, but this catches tinker,
     * seeders, and anything else, so the last super_admin can never be deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $user): void {
            if ($user->isLastSuperAdmin()) {
                throw new \RuntimeException(__('users.guard.last_super_admin_delete'));
            }
        });
    }
}
