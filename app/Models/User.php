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
     * Hard backstop for the invariant, at the correct layer: refuse the delete
     * BEFORE it begins. This ordering is the whole point — Spatie's own deleting
     * hook detaches a model's roles the instant a delete starts, so a guard that
     * runs *during* the deleting event only fires after the roles are already gone,
     * and outside a surrounding transaction (a bare tinker delete) that detach
     * commits even when the row deletion is aborted. Throwing here, before
     * parent::delete(), means no delete machinery — Spatie's included — ever runs
     * for the last super_admin, so nothing is stripped. Filament's single and bulk
     * actions still guard this first with a friendly notice; this catches tinker,
     * seeders, and every other path.
     */
    public function delete(): ?bool
    {
        if ($this->isLastSuperAdmin()) {
            throw new \RuntimeException(__('users.guard.last_super_admin_delete'));
        }

        return parent::delete();
    }
}
