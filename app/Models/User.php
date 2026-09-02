<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * A User is a trainer (the tenant). All client, plan, session and ledger data hangs off a user.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'business_name',
        'phone',
        'gst_number',
        'gst_registered',
        'gst_rate',
        'payment_methods',
        'etransfer_email',
        'booking_instructions',
        'timezone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'gst_registered' => 'boolean',
            'gst_rate' => 'decimal:2',
            'payment_methods' => 'array',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Effective GST rate as a percentage (0 when the trainer is not registered).
     */
    public function effectiveGstRate(): float
    {
        return $this->gst_registered ? (float) $this->gst_rate : 0.0;
    }

    /**
     * Payment methods this trainer accepts. Defaults to every non-card method.
     *
     * @return list<PaymentMethod>
     */
    public function enabledPaymentMethods(): array
    {
        $values = $this->payment_methods;

        if ($values === null) {
            return PaymentMethod::cases();
        }

        return collect($values)
            ->map(fn ($value) => PaymentMethod::tryFrom($value))
            ->filter()
            ->values()
            ->all();
    }

    public function acceptsPaymentMethod(PaymentMethod $method): bool
    {
        return in_array($method, $this->enabledPaymentMethods(), true);
    }

    public function displayName(): string
    {
        return $this->business_name ?: $this->name;
    }
}
