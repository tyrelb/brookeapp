<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTrainer;
use Database\Factories\FamilyMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person inside a family client. Members share the client's Fitness Wallet and
 * email address; they exist so a session can charge for the three people who turned
 * up rather than for the household as a single body.
 *
 * Members are deactivated, never deleted, once they appear on a session.
 */
class FamilyMember extends Model
{
    /** @use HasFactory<FamilyMemberFactory> */
    use BelongsToTrainer, HasFactory;

    protected $fillable = [
        'user_id',
        'client_id',
        'name',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(SessionAttendeeMember::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** A member who has been on a session is kept for history; deactivate instead. */
    public function hasHistory(): bool
    {
        return $this->attendances()->exists();
    }
}
