<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletDetail extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wallet_details';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'wallet_address',
        'network',
        'is_connected',
        'bonus_claimed',
        'connected_at',
        'last_updated_at',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_connected' => 'boolean',
        'bonus_claimed' => 'boolean',
        'connected_at' => 'datetime',
        'last_updated_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        // Add any sensitive fields here if needed
    ];

    /**
     * Relationship with User model
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for connected wallets
     */
    public function scopeConnected($query)
    {
        return $query->where('is_connected', true);
    }

    /**
     * Scope for specific network
     */
    public function scopeNetwork($query, $network)
    {
        return $query->where('network', $network);
    }

    /**
     * Scope for wallets with unclaimed bonus
     */
    public function scopeBonusAvailable($query)
    {
        return $query->where('bonus_claimed', false);
    }

    /**
     * Scope for wallets by user
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Mark wallet as connected
     */
    public function markAsConnected(): bool
    {
        return $this->update([
            'is_connected' => true,
            'connected_at' => now(),
            'last_updated_at' => now(),
        ]);
    }

    /**
     * Mark wallet as disconnected
     */
    public function markAsDisconnected(): bool
    {
        return $this->update([
            'is_connected' => false,
            'last_updated_at' => now(),
        ]);
    }

    /**
     * Mark bonus as claimed
     */
    public function markBonusAsClaimed(): bool
    {
        return $this->update([
            'bonus_claimed' => true,
            'last_updated_at' => now(),
        ]);
    }

    /**
     * Update wallet address
     */
    public function updateAddress(string $address): bool
    {
        return $this->update([
            'wallet_address' => $address,
            'last_updated_at' => now(),
        ]);
    }

    /**
     * Check if wallet is eligible for bonus
     */
    public function isEligibleForBonus(): bool
    {
        return $this->is_connected && !$this->bonus_claimed;
    }

    /**
     * Check if wallet address is valid format
     */
    public function isValidAddress(): bool
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $this->wallet_address) === 1;
    }

    /**
     * Get formatted address for display (0x1234...5678)
     */
    public function getFormattedAddress(): string
    {
        if (strlen($this->wallet_address) <= 10) {
            return $this->wallet_address;
        }
        
        return substr($this->wallet_address, 0, 6) . '...' . substr($this->wallet_address, -4);
    }

    /**
     * Get connection duration in human readable format
     */
    public function getConnectionDuration(): string
    {
        if (!$this->connected_at) {
            return 'Not connected';
        }

        return $this->connected_at->diffForHumans();
    }

    /**
     * Get days since connection
     */
    public function getDaysSinceConnection(): int
    {
        if (!$this->connected_at) {
            return 0;
        }

        return $this->connected_at->diffInDays(now());
    }

    /**
     * Check if this is the user's first wallet connection
     */
    public function isFirstConnection(): bool
    {
        return $this->user->walletDetail()->count() === 1 && 
               $this->connected_at->equalTo($this->created_at);
    }

    /**
     * Get metadata value by key
     */
    public function getMetadataValue(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Set metadata value
     */
    public function setMetadataValue(string $key, $value): bool
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;
        
        return $this->update(['metadata' => $metadata]);
    }

    /**
     * Boot method for model events
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-set last_updated_at when any field changes
        static::updating(function ($walletDetail) {
            $walletDetail->last_updated_at = now();
        });

        // Auto-set connected_at when wallet is first connected
        static::saving(function ($walletDetail) {
            if ($walletDetail->isDirty('is_connected') && $walletDetail->is_connected) {
                $walletDetail->connected_at = now();
            }
        });
    }
}