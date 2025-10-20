<?php
// app/Models/Task.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $casts = [
        'reward_amount' => 'decimal:4',
        'max_attempts_per_day' => 'integer',
        'cooldown_minutes' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_available' => 'boolean',
        'metadata' => 'array',
    ];

  /**
     * Relationship with task progress
     */
    public function taskProgress()
    {
        return $this->hasMany(UserTaskProgress::class);
    }

    /**
     * Check if user has completed a task today
     */
    public function hasCompletedTaskToday($taskType)
    {
        return $this->taskProgress()
            ->where('task_type', $taskType)
            ->whereDate('completion_date', today())
            ->exists();
    }

    /**
     * Get today's task attempts for a specific task type
     */
    public function getTodayTaskAttempts($taskType)
    {
        $progress = $this->taskProgress()
            ->where('task_type', $taskType)
            ->whereDate('completion_date', today())
            ->first();

        return $progress ? $progress->attempts_count : 0;
    }
}