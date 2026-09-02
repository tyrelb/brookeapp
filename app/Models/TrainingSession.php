<?php

namespace App\Models;

use App\Enums\SessionStatus;
use App\Models\Concerns\BelongsToTrainer;
use Database\Factories\TrainingSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingSession extends Model
{
    /** @use HasFactory<TrainingSessionFactory> */
    use BelongsToTrainer, HasFactory;

    protected $fillable = [
        'user_id',
        'service_id',
        'starts_at',
        'duration_minutes',
        'status',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => SessionStatus::class,
            'duration_minutes' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(SessionAttendee::class);
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'session_attendees')
            ->withPivot(['attended', 'subtotal', 'gst_amount', 'total'])
            ->withTimestamps();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Number of people who actually attended; drives the per-person rate tier.
     */
    public function headcount(): int
    {
        return $this->attendees->where('attended', true)->count();
    }

    public function isCompleted(): bool
    {
        return $this->status === SessionStatus::Completed;
    }

    public function isScheduled(): bool
    {
        return $this->status === SessionStatus::Scheduled;
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', SessionStatus::Completed->value);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('starts_at', [$from, $to]);
    }
}
