<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TrainerScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenancy: the model has a user_id column pointing at the owning trainer.
 */
trait BelongsToTrainer
{
    public static function bootBelongsToTrainer(): void
    {
        static::addGlobalScope(new TrainerScope);

        static::creating(function (Model $model) {
            if (empty($model->user_id) && auth()->check()) {
                $model->user_id = auth()->id();
            }
        });
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Explicit tenant scoping for console / queued contexts where nobody is logged in.
     */
    public function scopeForTrainer(Builder $query, User|int $trainer): Builder
    {
        return $query->withoutGlobalScope(TrainerScope::class)
            ->where($this->qualifyColumn('user_id'), $trainer instanceof User ? $trainer->id : $trainer);
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }
}
