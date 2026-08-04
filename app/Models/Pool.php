<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A pool is the unit that promotes together (P3-7, D28): one Update cuts a whole
 * batch of member apps over at once, so an operator running twenty-plus instances
 * isn't clicking Update per app. Single-membership — a Repository points at one
 * pool or none — because a pool answers exactly one question, "what promotes
 * together," and that is naturally single-valued.
 *
 * Membership that is inherently many-to-many (who may act, what policy applies)
 * is a separate roles/tags axis and is deliberately NOT modeled here — one word,
 * one job. The table stays minimal now; the later gated-auto timer and its health
 * gate (a separate P3-8-class item) attach to the pool and grow columns without a
 * data migration.
 */
class Pool extends Model
{
    protected $fillable = [
        'name', 'label',
    ];

    /**
     * Fill name from label when it isn't set explicitly, so an inline "create
     * pool" needs only the display label — the same slug-from-name pattern the
     * Repository model uses to derive its own stable identifier.
     */
    protected static function booted(): void
    {
        static::saving(function (self $pool): void {
            if (blank($pool->name)) {
                $pool->name = self::deriveName($pool->label);
            }
        });
    }

    /** The pool's stable identifier: its label, slugified. */
    public static function deriveName(?string $label): string
    {
        $name = (string) Str::of((string) $label)->slug();

        return $name !== '' ? $name : 'pool';
    }

    /** The member apps that promote together as this pool. */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }
}
