<?php

namespace App\Models;

use Database\Factories\SessionAttendeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionAttendee extends Model
{
    /** @use HasFactory<SessionAttendeeFactory> */
    use HasFactory;

    protected $fillable = [
        'training_session_id',
        'client_id',
        'attended',
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

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
