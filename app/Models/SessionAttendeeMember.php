<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which member of a family client was in the room for one session, and what their
 * share of the charge was. The name is snapshotted so a receipt keeps reading the
 * way it read on the day.
 */
class SessionAttendeeMember extends Model
{
    protected $fillable = [
        'session_attendee_id',
        'family_member_id',
        'member_name',
        'attended',
        'price_override',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'attended' => 'boolean',
            'price_override' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(SessionAttendee::class, 'session_attendee_id');
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class);
    }
}
