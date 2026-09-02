<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTrainer;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToTrainer, HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'duration_minutes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'duration_minutes' => 'integer',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(PlanRate::class);
    }

    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }
}
