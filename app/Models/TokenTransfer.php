<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TokenTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'amount',
        'currency',
        'description',
        'verification_token',
        'status',
        'verified_at'
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'verified_at' => 'datetime'
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_FAILED = 'failed';

    // Relationship with sender
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    // Relationship with recipient
    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    // Check if transfer is pending
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    // Check if transfer is completed
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    // Check if transfer is cancelled
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}