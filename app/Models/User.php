<?php
// app/Models/User.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'email',
        'password',
        'first_name',
        'last_name',
        'uid',
        'wallet_address',
        'is_verified',
        'referral_code',
        'referred_by',
        'token_balance',
        'usdc_balance',
        'mining_streak',
        'last_mining_at',
        'referral_usdc_balance', 
        'referral_token_balance', 
        'can_claim_referral_usdc',
        // KYC fields
        'kyc_status',
        'current_kyc_id',
        'kyc_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'referral_usdc_balance' => 'decimal:2',
            'referral_cmeme_balance' => 'decimal:2',
            'kyc_verified_at' => 'datetime',
        ];
    }

    // Relationship with referred users
    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    // Relationship with referrer
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    // KYC Relationships
    public function kycVerifications()
    {
        return $this->hasMany(KycVerification::class);
    }

    public function currentKyc()
    {
        return $this->belongsTo(KycVerification::class, 'current_kyc_id');
    }

    // KYC Status Checkers
    public function isKycPending(): bool
    {
        return $this->kyc_status === 'pending';
    }

    public function isKycVerified(): bool
    {
        return $this->kyc_status === 'verified';
    }

    public function isKycRejected(): bool
    {
        return $this->kyc_status === 'rejected';
    }

    public function hasSubmittedKyc(): bool
    {
        return $this->kyc_status !== 'not_submitted';
    }

    public function getLatestKyc()
    {
        return $this->kycVerifications()->latest()->first();
    }

    /**
     * Update KYC status and link current KYC
     */
    public function updateKycStatus(string $status, KycVerification $kyc = null): bool
    {
        $updateData = ['kyc_status' => $status];
        
        if ($kyc) {
            $updateData['current_kyc_id'] = $kyc->id;
        }

        if ($status === 'verified') {
            $updateData['kyc_verified_at'] = now();
            $updateData['is_verified'] = true;
        }

        return $this->update($updateData);
    }

    /**
     * Check if user can submit new KYC
     */
    public function canSubmitKyc(): bool
    {
        // Allow new submission if rejected or if no current submission exists
        return $this->kyc_status === 'rejected' || 
               $this->kyc_status === 'not_submitted' ||
               ($this->currentKyc && $this->currentKyc->isRejected());
    }

// P2P Relationships
public function p2pTradesAsSeller()
{
    return $this->hasMany(P2PTrade::class, 'seller_id');
}

public function p2pTradesAsBuyer()
{
    return $this->hasMany(P2PTrade::class, 'buyer_id');
}

public function p2pTradeProofs()
{
    return $this->hasMany(P2PTradeProof::class, 'uploaded_by');
}

public function p2pDisputesRaised()
{
    return $this->hasMany(P2PDispute::class, 'raised_by');
}

// P2P Stats
public function getP2PCompletedTradesCount()
{
    return $this->p2pTradesAsSeller()
        ->where('status', 'completed')
        ->count() + 
        $this->p2pTradesAsBuyer()
        ->where('status', 'completed')
        ->count();
}

public function getP2PSuccessRate()
{
    $completed = $this->getP2PCompletedTradesCount();
    $total = $this->p2pTradesAsSeller()->count() + $this->p2pTradesAsBuyer()->count();
    
    return $total > 0 ? round(($completed / $total) * 100, 2) : 100;
}
}