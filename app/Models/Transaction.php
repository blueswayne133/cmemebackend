<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;


class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'description',
        'metadata',
        'related_model',
        'related_id',
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Transaction types
    const TYPE_EARNING = 'earning';
    const TYPE_MINING = 'mining';
    const TYPE_P2P = 'p2p';
    const TYPE_TRANSFER = 'transfer';
    const TYPE_REFERRAL = 'referral';
    const TYPE_STAKING = 'staking';
    const TYPE_WITHDRAWAL = 'withdrawal';
    const TYPE_DEPOSIT = 'deposit';

    /**
     * Relationship with User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for earnings transactions
     */
    public function scopeEarnings($query)
    {
        return $query->where('type', self::TYPE_EARNING);
    }

    /**
     * Scope for mining transactions
     */
    public function scopeMining($query)
    {
        return $query->where('type', self::TYPE_MINING);
    }

    /**
     * Scope for referral transactions
     */
    public function scopeReferral($query)
    {
        return $query->where('type', self::TYPE_REFERRAL);
    }

    /**
     * Scope for a specific date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope for positive amounts (earnings)
     */
    public function scopePositive($query)
    {
        return $query->where('amount', '>', 0);
    }

    /**
     * Get transaction types for select options
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_EARNING => 'Earning',
            self::TYPE_MINING => 'Mining',
            self::TYPE_P2P => 'P2P Trade',
            self::TYPE_TRANSFER => 'Transfer',
            self::TYPE_REFERRAL => 'Referral',
            self::TYPE_STAKING => 'Staking',
            self::TYPE_WITHDRAWAL => 'Withdrawal',
            self::TYPE_DEPOSIT => 'Deposit',
        ];
    }

    /**
     * Get type label
     */
    public function getTypeLabel(): string
    {
        return self::getTypes()[$this->type] ?? ucfirst($this->type);
    }

    /**
     * Check if transaction is positive (earning)
     */
    public function isEarning(): bool
    {
        return in_array($this->type, [
            self::TYPE_EARNING,
            self::TYPE_MINING,
            self::TYPE_REFERRAL,
            self::TYPE_STAKING
        ]) && $this->amount > 0;
    }

    /**
     * Create a mining reward transaction
     */
    public static function createMiningReward(User $user, float $amount, string $description = null): self
    {
        return self::create([
            'user_id' => $user->id,
            'type' => self::TYPE_MINING,
            'amount' => $amount,
            'description' => $description ?? 'Daily mining reward',
            'metadata' => [
                'reward_type' => 'mining',
                'streak' => $user->mining_streak ?? 1,
            ],
        ]);
    }

    /**
     * Create a referral reward transaction
     */
    public static function createReferralReward(User $user, float $amount, User $referredUser = null, string $currency = 'CMEME'): self
    {
        $description = $referredUser ? 
            "Referral reward for {$referredUser->username} (KYC Verified)" : 
            'Referral reward claim';

        return self::create([
            'user_id' => $user->id,
            'type' => self::TYPE_REFERRAL,
            'amount' => $amount,
            'description' => $description,
            'metadata' => [
                'reward_type' => 'referral',
                'referred_user_id' => $referredUser?->id,
                'currency' => $currency,
                'kyc_verified' => (bool) $referredUser,
            ],
        ]);
    }

    /**
     * Create a P2P trade transaction
     */
    public static function createP2PTransaction(User $user, float $amount, string $description, array $metadata = []): self
    {
        return self::create([
            'user_id' => $user->id,
            'type' => self::TYPE_P2P,
            'amount' => $amount,
            'description' => $description,
            'metadata' => array_merge($metadata, [
                'trade_type' => $amount > 0 ? 'income' : 'expense',
            ]),
        ]);
    }

    /**
     * Get total earnings for a user in date range
     */
    public static function getTotalEarnings(User $user, $startDate = null, $endDate = null): float
    {
        $query = self::where('user_id', $user->id)
            ->where(function($q) {
                $q->where('type', self::TYPE_EARNING)
                  ->orWhere('type', self::TYPE_MINING)
                  ->orWhere('type', self::TYPE_REFERRAL);
            })
            ->positive();

        if ($startDate && $endDate) {
            $query->dateRange($startDate, $endDate);
        }

        return (float) $query->sum('amount');
    }

    /**
     * Get leaderboard data for a period
     */
    public static function getLeaderboardData($startDate, $endDate, $limit = 100)
    {
        return self::select([
                'user_id',
                DB::raw('SUM(amount) as total_earned'),
                DB::raw('COUNT(*) as transaction_count')
            ])
            ->with('user') // Eager load user relationship
            ->where(function($query) {
                $query->where('type', self::TYPE_EARNING)
                    ->orWhere('type', self::TYPE_MINING)
                    ->orWhere('type', self::TYPE_REFERRAL);
            })
            ->positive()
            ->dateRange($startDate, $endDate)
            ->groupBy('user_id')
            ->orderBy('total_earned', 'DESC')
            ->limit($limit)
            ->get();
    }
}