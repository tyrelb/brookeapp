<?php

namespace App\Models;

use App\Enums\Attendance;
use Database\Factories\SessionAttendeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SessionAttendee extends Model
{
    /** @use HasFactory<SessionAttendeeFactory> */
    use HasFactory;

    /**
     * Always loaded: a dozen callers eager-load `attendees` to count people, and
     * missing the member rows there would mean a quiet N+1 on every report.
     */
    protected $with = ['members'];

    /** Set in memory too, so a row created without it still reads as a plain attendance. */
    protected $attributes = ['late_cancelled' => false];

    protected $fillable = [
        'training_session_id',
        'client_id',
        'attended',
        'late_cancelled',
        'client_note',
        'price_override',
        'subtotal',
        'gst_amount',
        'total',
        'wallet_transaction_id',
        'invite_sent_at',
        'receipt_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attended' => 'boolean',
            'late_cancelled' => 'boolean',
            'price_override' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'invite_sent_at' => 'datetime',
            'receipt_sent_at' => 'datetime',
        ];
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<SessionAttendeeMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(SessionAttendeeMember::class, 'session_attendee_id')->orderBy('id');
    }

    /**
     * How many people this row puts on the bill, which is what sets the rate tier. A family
     * contributes the members who were ticked; everyone else contributes themselves. A late
     * cancel still counts: they held their place, so everyone pays the rate of the group
     * as it was booked.
     */
    public function peopleCount(): int
    {
        if (! $this->attended) {
            return 0;
        }

        return $this->client?->isOnFamilyPlan()
            ? $this->members->where('attended', true)->count()
            : 1;
    }

    /** How many people this row actually put in the room, which is what the gym charges for. */
    public function roomCount(): int
    {
        return $this->isLateCancel() ? 0 : $this->peopleCount();
    }

    public function attendance(): Attendance
    {
        return Attendance::fromFlags((bool) $this->attended, (bool) $this->late_cancelled);
    }

    public function isLateCancel(): bool
    {
        return $this->attendance() === Attendance::LateCancel;
    }

    /** @return list<string> the people this row brought, by name */
    public function peopleNames(): array
    {
        if ($this->client?->isOnFamilyPlan()) {
            return $this->members->where('attended', true)->pluck('member_name')->values()->all();
        }

        return $this->client ? [$this->client->full_name] : [];
    }

    /**
     * The one place member rows are written. Ids come from a browser, so anything that
     * is not currently a member of this client is dropped rather than trusted, and the
     * row's own `attended` is recomputed from the ticks so the two cannot disagree.
     *
     * @param  array<int, array{attended?: bool, price_override?: ?float}>  $selection  keyed by family member id
     */
    public function syncMembers(array $selection): void
    {
        $names = $this->client?->members()->pluck('name', 'id') ?? collect();
        $kept = [];

        foreach ($selection as $memberId => $state) {
            $memberId = (int) $memberId;

            if (! $names->has($memberId)) {
                continue;
            }

            $this->members()->updateOrCreate(
                ['family_member_id' => $memberId],
                [
                    'member_name' => $names[$memberId],
                    'attended' => (bool) ($state['attended'] ?? false),
                    'price_override' => $state['price_override'] ?? null,
                ],
            );

            $kept[] = $memberId;
        }

        $this->members()->whereNotIn('family_member_id', $kept)->delete();
        $this->load('members');

        $this->update(['attended' => $this->members->where('attended', true)->isNotEmpty()]);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
