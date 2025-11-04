<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class UserTaskProgress extends Model
{
    use HasFactory;

    protected $table = 'user_task_progress';

    protected $fillable = [
        'user_id',
        'task_id',
        'task_type',
        'attempts_count',
        'completion_date',
        'last_completed_at',
        'proof_data',
        'metadata',
    ];

    protected $casts = [
        'completion_date' => 'date',
        'last_completed_at' => 'datetime',
        'proof_data' => 'array',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Set the completion date automatically when setting last_completed_at
     */
    public function setLastCompletedAtAttribute($value)
    {
        $this->attributes['last_completed_at'] = $value;
        $this->attributes['completion_date'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    /**
     * Scope for today's progress
     */
    public function scopeToday($query)
    {
        return $query->whereDate('completion_date', today());
    }

    /**
     * Scope for specific user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for specific task
     */
    public function scopeForTask($query, $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    /**
     * Scope for engagement tasks
     */
    public function scopeEngagementTasks($query)
    {
        return $query->whereHas('task', function($q) {
            $q->whereIn('type', [
                Task::TYPE_FOLLOW_X,
                Task::TYPE_LIKE_X,
                Task::TYPE_RETWEET_X,
                Task::TYPE_COMMENT_X,
                Task::TYPE_QUOTE_TWEET,
                Task::TYPE_JOIN_TWITTER_SPACE,
            ]);
        });
    }

    /**
     * Scope for tasks with screenshot proof
     */
    public function scopeWithScreenshot($query)
    {
        return $query->whereNotNull('proof_data->screenshot_url');
    }

    /**
     * Get screenshot proof if available
     */
    public function getScreenshotProofAttribute()
    {
        return $this->proof_data['screenshot_url'] ?? null;
    }

    /**
     * Get screenshot public ID if available
     */
    public function getScreenshotPublicIdAttribute()
    {
        return $this->proof_data['screenshot_public_id'] ?? null;
    }

    /**
     * Check if task has screenshot proof
     */
    public function hasScreenshotProof()
    {
        return !empty($this->proof_data['screenshot_url']);
    }

    /**
     * Get completion details for engagement tasks
     */
    public function getCompletionDetailsAttribute()
    {
        if (!$this->task || !$this->task->isEngagementTask()) {
            return null;
        }

        return [
            'has_screenshot' => $this->hasScreenshotProof(),
            'screenshot_url' => $this->screenshot_proof,
            'completed_at' => $this->last_completed_at,
            'task_type' => $this->task_type,
            'reward_earned' => $this->attempts_count * ($this->task->reward_amount ?? 0),
        ];
    }
}