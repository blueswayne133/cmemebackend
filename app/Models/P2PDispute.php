<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class P2PDispute extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'p2p_disputes'; // Explicitly set the table name

    protected $fillable = [
        'trade_id',
        'raised_by',
        'reason',
        'evidence',
        'status',
        'resolution',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'evidence' => 'array',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(P2PTrade::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    public function markAsResolved(string $resolution, User $resolvedBy): bool
    {
        return $this->update([
            'status' => 'resolved',
            'resolution' => $resolution,
            'resolved_by' => $resolvedBy->id,
            'resolved_at' => now(),
        ]);
    }
}