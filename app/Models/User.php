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
        'avatar_url',
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
        'last_login_at',
        // KYC fields
        'kyc_status',
        'current_kyc_id',
        'kyc_verified_at',
            'phone',
    'phone_verified',
    'two_factor_enabled',
    'two_factor_type',
    'two_factor_secret',
    'backup_codes',
    'two_factor_enabled_at',
       // Email verification fields
        'email_verified_at',
        'email_verification_code',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code', 
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'referral_usdc_balance' => 'decimal:2',
            'referral_cmeme_balance' => 'decimal:2',
            'kyc_verified_at' => 'datetime',
            'phone_verified' => 'boolean',
        'two_factor_enabled' => 'boolean',
        'backup_codes' => 'array',
        'two_factor_enabled_at' => 'datetime',
         'last_login_at' => 'datetime',
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

public function taskProgress()
{
    return $this->hasMany(UserTaskProgress::class);
}


/**
 * Check if user has connected Twitter
 */
public function hasConnectedTwitter(): bool
{
    return (bool) $this->twitter_connected;
}

/**
 * Check if user has connected Telegram
 */
public function hasConnectedTelegram(): bool
{
    return (bool) $this->telegram_connected;
}

/**
 * Get today's task progress
 */
public function getTodayTaskProgress()
{
    return $this->taskProgress()
        ->whereDate('completion_date', today())
        ->get();
}

/**
 * Get completed tasks count for today
 */
public function getTodayCompletedTasksCount(): int
{
    return $this->taskProgress()
        ->whereDate('completion_date', today())
        ->whereHas('task', function ($query) {
            $query->whereColumn('user_task_progress.attempts_count', '>=', 'tasks.max_attempts_per_day');
        })
        ->count();
}




/**
 * Relationship with wallet details
 */
public function walletDetail()
{
    return $this->hasOne(WalletDetail::class);
}

/**
 * Check if user has connected wallet
 */
public function hasConnectedWallet(): bool
{
    return $this->walletDetail && $this->walletDetail->is_connected;
}

/**
 * Check if user has claimed wallet bonus
 */
public function hasClaimedWalletBonus(): bool
{
    return $this->walletDetail && $this->walletDetail->bonus_claimed;
}

/**
 * Get connected wallet
 */
public function getConnectedWallet()
{
    return $this->walletDetail()->connected()->first();
}

/**
 * Get wallet bonus eligibility
 */
public function isEligibleForWalletBonus(): bool
{
    return $this->walletDetail && $this->walletDetail->isEligibleForBonus();
}


// Add these relationships to User.php
public function securitySettings()
{
    return $this->hasOne(UserSecuritySetting::class);
}

public function twoFactorCodes()
{
    return $this->hasMany(TwoFactorCode::class);
}

// Add these methods to User.php
public function hasTwoFactorEnabled(): bool
{
    return $this->two_factor_enabled;
}

public function getTwoFactorMethods(): array
{
    if (!$this->securitySettings) {
        return [];
    }
    return $this->securitySettings->getEnabled2FAMethods();
}

public function hasPhoneVerified(): bool
{
    return $this->phone_verified && !empty($this->phone);
}

public function generateBackupCodes(): array
{
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8-character backup codes
    }
    
    $this->update([
        'backup_codes' => $codes
    ]);
    
    return $codes;
}

public function useBackupCode(string $code): bool
{
    $backupCodes = $this->backup_codes ?? [];
    $index = array_search($code, $backupCodes);
    
    if ($index !== false) {
        unset($backupCodes[$index]);
        $this->update(['backup_codes' => array_values($backupCodes)]);
        return true;
    }
    
    return false;
}

public function hasBackupCodes(): bool
{
    return !empty($this->backup_codes);
}

protected static function boot()
{
    parent::boot();

    static::created(function ($user) {
        $user->securitySettings()->create();
    });
}


/**
 * Get the user's full name
 */
public function getNameAttribute(): string
{
    if ($this->first_name && $this->last_name) {
        return $this->first_name . ' ' . $this->last_name;
    }
    
    return $this->username;
}



    /**
     * Check if user has verified email
     */
    public function hasVerifiedEmail(): bool
    {
        return !is_null($this->email_verified_at);
    }

    /**
     * Mark email as verified
     */
    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
            'is_verified' => true,
            'email_verification_code' => null,
        ])->save();
    }


}