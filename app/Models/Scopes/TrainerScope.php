<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query to rows owned by the authenticated trainer.
 * Console commands and jobs run without an authenticated user and must
 * scope explicitly (see BelongsToTrainer::scopeForTrainer).
 */
class TrainerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (auth()->check()) {
            $builder->where($model->qualifyColumn('user_id'), auth()->id());
        }
    }
}
