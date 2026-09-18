<?php

namespace App\Models;

use App\Enums\SessionStatus;
use App\Models\Concerns\BelongsToTrainer;
use App\Support\CoverNames;
use Carbon\Carbon;
use Database\Factories\TrainingSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TrainingSession extends Model
{
    /** @use HasFactory<TrainingSessionFactory> */
    use BelongsToTrainer, HasFactory;

    protected $fillable = [
        'user_id',
        'service_id',
        'gym_id',
        'gym_billable',
        'gym_cover',
        'cover_names',
        'cover_subtotal',
        'cover_gst_amount',
        'cover_gst_rate',
        'session_series_id',
        'starts_at',
        'duration_minutes',
        'status',
        'completed_at',
        'notes',
        'ics_uid',
        'ics_sequence',
        'invites_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => SessionStatus::class,
            'duration_minutes' => 'integer',
            'gym_billable' => 'boolean',
            'gym_cover' => 'boolean',
            'cover_names' => 'array',
            'cover_subtotal' => 'decimal:2',
            'cover_gst_amount' => 'decimal:2',
            'cover_gst_rate' => 'decimal:2',
            'ics_sequence' => 'integer',
            'invites_sent_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(SessionSeries::class, 'session_series_id');
    }

    public function isInSeries(): bool
    {
        return $this->session_series_id !== null;
    }

    /** Later scheduled sessions of the same series (this one excluded). */
    public function followingInSeries(): Collection
    {
        if (! $this->series) {
            return collect();
        }

        return $this->series->scheduledFrom($this)->reject(fn (TrainingSession $s) => $s->is($this))->values();
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
     * People on the bill, not attendee rows: a family client contributes the members
     * who were there. Drives the per-person rate tier, so a late cancel counts — the
     * group is priced as it was booked.
     */
    public function headcount(): int
    {
        if ($this->isCover()) {
            return $this->coverPeople();
        }

        return (int) $this->attendees->sum(fn (SessionAttendee $attendee) => $attendee->peopleCount());
    }

    /**
     * People who were actually in the room: the headcount less anyone who cancelled late.
     * This is what the gym charges for, and what counts as someone trained.
     */
    public function roomHeadcount(): int
    {
        if ($this->isCover()) {
            return $this->coverPeople();
        }

        return (int) $this->attendees->sum(fn (SessionAttendee $attendee) => $attendee->roomCount());
    }

    /**
     * Everyone who was in the room, by name, with families expanded into their members.
     *
     * @return list<string>
     */
    public function peopleNames(): array
    {
        if ($this->isCover()) {
            return $this->coverNames();
        }

        return $this->attendees
            ->flatMap(fn (SessionAttendee $attendee) => $attendee->roomCount() > 0 ? $attendee->peopleNames() : [])
            ->values()
            ->all();
    }

    /**
     * Who the session is with, as a calendar shows it: everyone booked, whether or not they
     * came, so a no-show still reads as their session. Needs attendees.client loaded.
     */
    public function displayName(): string
    {
        $names = $this->isCover()
            ? $this->coverNames()
            : $this->attendees->pluck('client.full_name')->filter()->values()->all();

        return $names === [] ? 'No attendees yet' : Arr::join($names, ', ', ' and ');
    }

    /**
     * A session where the trainer covered the gym's own clients: the gym pays her,
     * she owes it nothing for the space, and no client wallet is touched.
     */
    public function isCover(): bool
    {
        return (bool) $this->gym_cover;
    }

    /** @return list<string> */
    public function coverNames(): array
    {
        return CoverNames::clean($this->cover_names ?? []);
    }

    public function coverPeople(): int
    {
        return count($this->coverNames());
    }

    /** What the gym owes for this cover session, GST included. */
    public function coverTotal(): float
    {
        return round((float) $this->cover_subtotal + (float) $this->cover_gst_amount, 2);
    }

    public function scopeCover(Builder $query, bool $cover = true): Builder
    {
        return $query->where('gym_cover', $cover);
    }

    public function isCompleted(): bool
    {
        return $this->status === SessionStatus::Completed;
    }

    public function isScheduled(): bool
    {
        return $this->status === SessionStatus::Scheduled;
    }

    public function endsAt(): Carbon
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }

    public function isCancelled(): bool
    {
        return $this->status === SessionStatus::Cancelled;
    }

    public function invitesWereSent(): bool
    {
        return $this->invites_sent_at !== null;
    }

    /**
     * Stable identifier for calendar invites so updates and cancellations replace the original event.
     */
    public function ensureIcsUid(): string
    {
        if (! $this->ics_uid) {
            $this->forceFill(['ics_uid' => (string) Str::uuid().'@brookeapp'])->save();
        }

        return $this->ics_uid;
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
