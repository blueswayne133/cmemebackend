<?php
// app/Models/KycVerification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'admin_id',
        'document_type',
        'document_number',
        'document_front_path',
        'document_back_path',
        'status',
        'rejection_reason',
        'verification_notes',
        'verification_score',
        'verification_details',
        'submitted_at',
        'verified_at',
        'verified_by_admin_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'verified_by_admin_at' => 'datetime',
        'verification_score' => 'decimal:2',
        'verification_details' => 'array',
    ];

    /**
     * Relationship with User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship with Admin who verified
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Scope for pending verifications
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for verified verifications
     */
    public function scopeVerified($query)
    {
        return $query->where('status', 'verified');
    }

    /**
     * Scope for rejected verifications
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Check if KYC is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if KYC is verified
     */
    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    /**
     * Check if KYC is rejected
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Get document type label
     */
    public function getDocumentTypeLabel(): string
    {
        return match($this->document_type) {
            'passport' => 'Passport',
            'drivers_license' => 'Driver\'s License',
            'national_id' => 'National ID Card',
            default => ucfirst(str_replace('_', ' ', $this->document_type))
        };
    }

    /**
     * Get status badge color
     */
    public function getStatusColor(): string
    {
        return match($this->status) {
            'verified' => 'success',
            'pending' => 'warning',
            'rejected' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Mark as verified by admin
     */
    public function markAsVerified(int $adminId, string $notes = null): bool
    {
        return $this->update([
            'status' => 'verified',
            'admin_id' => $adminId,
            'verified_at' => now(),
            'verified_by_admin_at' => now(),
            'verification_notes' => $notes,
            'rejection_reason' => null, // Clear rejection reason if any
        ]);
    }

    /**
     * Mark as rejected by admin
     */
    public function markAsRejected(int $adminId, string $reason, string $notes = null): bool
    {
        return $this->update([
            'status' => 'rejected',
            'admin_id' => $adminId,
            'rejection_reason' => $reason,
            'verified_at' => now(),
            'verified_by_admin_at' => now(),
            'verification_notes' => $notes,
        ]);
    }

    /**
     * Check if KYC was verified by admin
     */
    public function wasVerifiedByAdmin(): bool
    {
        return !is_null($this->admin_id) && !is_null($this->verified_by_admin_at);
    }

    /**
     * Check if KYC can be manually verified
     */
    public function canBeManuallyVerified(): bool
    {
        return $this->status === 'pending' || $this->status === 'rejected';
    }

    /**
     * Check if KYC can be manually rejected
     */
    public function canBeManuallyRejected(): bool
    {
        return $this->status === 'pending' || $this->status === 'verified';
    }
}