<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'reward_amount',
        'reward_type',
        'type',
        'max_attempts_per_day',
        'cooldown_minutes',
        'sort_order',
        'is_active',
        'is_available',
        'metadata',
        'action_url',
        'social_platform',
        'required_content'
    ];

    protected $casts = [
        'reward_amount' => 'decimal:8',
        'is_active' => 'boolean',
        'is_available' => 'boolean',
        'metadata' => 'array',
    ];

    // Task types
    const TYPE_WATCH_ADS = 'watch_ads';
    const TYPE_DAILY_STREAK = 'daily_streak';
    const TYPE_CONNECT_TWITTER = 'connect_twitter';
    const TYPE_CONNECT_TELEGRAM = 'connect_telegram';
    const TYPE_CONNECT_WALLET = 'connect_wallet';
    const TYPE_FOLLOW = 'follow';
    const TYPE_LIKE = 'like';
    const TYPE_COMMENT = 'comment';
    const TYPE_SHARE = 'share';
    const TYPE_RETWEET = 'retweet';
    const TYPE_JOIN_TELEGRAM = 'join_telegram';
    const TYPE_JOIN_DISCORD = 'join_discord';
    
    // New Engagement Task Types
    const TYPE_FOLLOW_X = 'follow_x';
    const TYPE_LIKE_X = 'like_x';
    const TYPE_RETWEET_X = 'retweet_x';
    const TYPE_COMMENT_X = 'comment_x';
    const TYPE_QUOTE_TWEET = 'quote_tweet';
    const TYPE_JOIN_TWITTER_SPACE = 'join_twitter_space';

    // Reward types
    const REWARD_CMEME = 'CMEME';
    const REWARD_USDC = 'USDC';

    public function taskProgress()
    {
        return $this->hasMany(UserTaskProgress::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function canUserComplete($user)
    {
        // Check if task is active and available
        if (!$this->is_active || !$this->is_available) {
            return false;
        }

        // Get today's progress for this user and task
        $userTask = UserTaskProgress::where('user_id', $user->id)
            ->where('task_id', $this->id)
            ->whereDate('completion_date', today())
            ->first();

        $currentAttempts = $userTask ? $userTask->attempts_count : 0;

        // Check daily attempt limit
        if ($currentAttempts >= $this->max_attempts_per_day) {
            return false;
        }

        // Check cooldown
        if ($userTask && $this->cooldown_minutes > 0) {
            $lastCompleted = $userTask->last_completed_at;
            $cooldownEnd = $lastCompleted->addMinutes($this->cooldown_minutes);
            
            if (now()->lt($cooldownEnd)) {
                return false;
            }
        }

        // Special checks for specific task types
        switch ($this->type) {
            case self::TYPE_CONNECT_TWITTER:
                $twitterHandle = UserSocialHandle::where('user_id', $user->id)
                    ->where('platform', 'twitter')
                    ->first();
                return !$twitterHandle;

            case self::TYPE_CONNECT_TELEGRAM:
                $telegramHandle = UserSocialHandle::where('user_id', $user->id)
                    ->where('platform', 'telegram')
                    ->first();
                return !$telegramHandle;

            case self::TYPE_CONNECT_WALLET:
                // Wallet task is one-time only
                $walletTaskCompleted = UserTaskProgress::where('user_id', $user->id)
                    ->where('task_type', self::TYPE_CONNECT_WALLET)
                    ->exists();
                return !$walletTaskCompleted;

            case self::TYPE_FOLLOW_X:
            case self::TYPE_LIKE_X:
            case self::TYPE_RETWEET_X:
            case self::TYPE_COMMENT_X:
            case self::TYPE_QUOTE_TWEET:
            case self::TYPE_JOIN_TWITTER_SPACE:
                // Engagement tasks can be completed once per day
                $engagementTaskCompleted = UserTaskProgress::where('user_id', $user->id)
                    ->where('task_id', $this->id)
                    ->whereDate('completion_date', today())
                    ->exists();
                return !$engagementTaskCompleted;
        }

        return true;
    }

    public function getTodayCompletionsAttribute()
    {
        return $this->taskProgress()
            ->whereDate('completion_date', today())
            ->count();
    }

    public function getTotalCompletionsAttribute()
    {
        return $this->taskProgress()->count();
    }

    /**
     * Check if task is an engagement task
     */
    public function isEngagementTask()
    {
        return in_array($this->type, [
            self::TYPE_FOLLOW_X,
            self::TYPE_LIKE_X,
            self::TYPE_RETWEET_X,
            self::TYPE_COMMENT_X,
            self::TYPE_QUOTE_TWEET,
            self::TYPE_JOIN_TWITTER_SPACE,
            self::TYPE_FOLLOW,
            self::TYPE_LIKE,
            self::TYPE_COMMENT,
            self::TYPE_SHARE,
            self::TYPE_RETWEET,
        ]);
    }

    /**
     * Check if task requires screenshot proof
     */
    public function requiresScreenshot()
    {
        return $this->required_content === 'screenshot' || in_array($this->type, [
            self::TYPE_FOLLOW_X,
            self::TYPE_LIKE_X,
            self::TYPE_RETWEET_X,
            self::TYPE_COMMENT_X,
            self::TYPE_QUOTE_TWEET,
            self::TYPE_JOIN_TWITTER_SPACE,
        ]);
    }

    /**
     * Get engagement task statistics
     */
    public function getEngagementStatsAttribute()
    {
        if (!$this->isEngagementTask()) {
            return null;
        }

        return [
            'total_completions' => $this->taskProgress()->count(),
            'screenshot_completions' => $this->taskProgress()
                ->whereNotNull('proof_data->screenshot_url')
                ->count(),
            'today_completions' => $this->taskProgress()
                ->whereDate('completion_date', today())
                ->count(),
        ];
    }
}