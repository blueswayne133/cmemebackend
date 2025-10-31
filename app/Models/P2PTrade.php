<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class P2PTrade extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'p2p_trades';

    protected $fillable = [
        'seller_id',
        'buyer_id',
        'type',
        'amount',
        'price',
        'total',
        'payment_method',
        'payment_details',
        'status',
        'terms',
        'time_limit',
        'expires_at',
        'paid_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'price' => 'decimal:8',
        'total' => 'decimal:8',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'payment_details' => 'array',
    ];

    // Relationships
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(P2PTradeProof::class);
    }

    public function dispute(): HasOne
    {
        return $this->hasOne(P2PDispute::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    // Status checkers
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isPaid(): bool
    {
        return !is_null($this->paid_at);
    }

    public function hasDispute(): bool
    {
        return $this->dispute()->exists();
    }

    public function hasProofs(): bool
    {
        return $this->proofs()->exists();
    }

    // Business logic
    public function markAsProcessing(): bool
    {
        return $this->update([
            'status' => 'processing',
            'expires_at' => now()->addMinutes($this->time_limit),
        ]);
    }

    public function markAsPaid(): bool
    {
        return $this->update([
            'paid_at' => now(),
        ]);
    }

    public function markAsCompleted(): bool
    {
        return $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function markAsCancelled(string $reason): bool
    {
        return $this->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
        ]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function getTimeRemainingAttribute(): string
    {
        if (!$this->expires_at) {
            return 'N/A';
        }

        $now = now();
        if ($this->expires_at->isPast()) {
            return 'Expired';
        }

        $diffInMinutes = $this->expires_at->diffInMinutes($now);
        return $diffInMinutes . ' min';
    }

    public function getPaymentMethodLabel(): string
    {
        return match($this->payment_method) {
            'bank_transfer' => 'Bank Transfer',
            'wise' => 'Wise',
            'paypal' => 'PayPal',
            'revolut' => 'Revolut',
            'usdc' => 'USDC',
            'usdt' => 'USDT',
            'other' => 'Other',
            default => ucfirst(str_replace('_', ' ', $this->payment_method))
        };
    }

    // Add this to make time_remaining accessible
    protected $appends = ['time_remaining'];
}