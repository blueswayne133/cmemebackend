<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class P2PTradeMessage extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'p2p_trade_messages';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'trade_id',
        'user_id',
        'message',
        'type',
        'is_system'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_system' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'is_own_message',
        'formatted_time'
    ];

    /**
     * Relationship with the trade.
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(P2PTrade::class, 'trade_id');
    }

    /**
     * Relationship with the user who sent the message.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Check if message is from the current authenticated user.
     */
    public function getIsOwnMessageAttribute(): bool
    {
        return $this->user_id === auth()->id();
    }

    /**
     * Get formatted time for display.
     */
    public function getFormattedTimeAttribute(): string
    {
        return $this->created_at->format('H:i');
    }

    /**
     * Get formatted date for display.
     */
    public function getFormattedDateAttribute(): string
    {
        if ($this->created_at->isToday()) {
            return 'Today';
        } elseif ($this->created_at->isYesterday()) {
            return 'Yesterday';
        } else {
            return $this->created_at->format('M j, Y');
        }
    }

    /**
     * Scope a query to only include system messages.
     */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope a query to only include user messages.
     */
    public function scopeUserMessages($query)
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope a query to only include messages for a specific trade.
     */
    public function scopeForTrade($query, $tradeId)
    {
        return $query->where('trade_id', $tradeId);
    }

    /**
     * Scope a query to order by latest messages.
     */
    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope a query to order by oldest messages first.
     */
    public function scopeOldestFirst($query)
    {
        return $query->orderBy('created_at', 'asc');
    }

    /**
     * Check if the message is a system message.
     */
    public function isSystem(): bool
    {
        return $this->is_system;
    }

    /**
     * Check if the message is a user message.
     */
    public function isUserMessage(): bool
    {
        return !$this->is_system;
    }

    /**
     * Create a system message for a trade.
     */
    public static function createSystemMessage(P2PTrade $trade, string $message, ?User $user = null): self
    {
        return self::create([
            'trade_id' => $trade->id,
            'user_id' => $user ? $user->id : $trade->seller_id,
            'message' => $message,
            'type' => 'system',
            'is_system' => true,
        ]);
    }

    /**
     * Create a user message for a trade.
     */
    public static function createUserMessage(P2PTrade $trade, User $user, string $message): self
    {
        return self::create([
            'trade_id' => $trade->id,
            'user_id' => $user->id,
            'message' => $message,
            'type' => 'user',
            'is_system' => false,
        ]);
    }

    /**
     * Get messages for a trade with user data.
     */
    public static function getTradeMessages($tradeId)
    {
        return self::with(['user:id,username,avatar_url'])
            ->forTrade($tradeId)
            ->oldestFirst()
            ->get();
    }

    /**
     * Mark message as read (if you implement read receipts later).
     */
    public function markAsRead(): bool
    {
        // You can implement read receipts later
        return true;
    }

    /**
     * Boot method for model events.
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-delete messages when trade is deleted
        static::deleting(function ($message) {
            // Additional cleanup if needed
        });

        // Validate message before saving
        static::saving(function ($message) {
            if (empty($message->message) || strlen(trim($message->message)) === 0) {
                return false;
            }
            
            // Limit message length
            if (strlen($message->message) > 1000) {
                $message->message = substr($message->message, 0, 1000);
            }
            
            return true;
        });
    }
}