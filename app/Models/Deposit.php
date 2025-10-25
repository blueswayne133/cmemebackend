<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'currency',
        'network',
        'transaction_hash',
        'from_wallet_address',
        'to_wallet_address',
        'status',
        'admin_notes',
        'approved_at',
        'rejected_at'
    ];

    protected $casts = [
        'amount' => 'decimal:6',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    // Relationship with User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scope for pending deposits
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    // Scope for approved deposits
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    // Check if deposit is pending
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    // Check if deposit is approved
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    // Check if deposit is rejected
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    // Approve deposit
    public function approve(string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_APPROVED,
            'admin_notes' => $notes,
            'approved_at' => now(),
            'rejected_at' => null,
        ]);
    }

    // Reject deposit
    public function reject(string $notes = null): bool
    {
        return $this->update([
            'status' => self::STATUS_REJECTED,
            'admin_notes' => $notes,
            'rejected_at' => now(),
            'approved_at' => null,
        ]);
    }
}