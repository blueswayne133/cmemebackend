<?php

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
}