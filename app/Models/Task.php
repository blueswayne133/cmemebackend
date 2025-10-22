<?php
// app/Models/Task.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Task extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
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

    /**
     * The attributes that should be cast.
     */
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
     * Relationship: a task has many user task progress records.
     */
    public function taskProgress()
    {
        return $this->hasMany(UserTaskProgress::class);
    }

    /**
     * Check if the user has completed this task today.
     *
     * @param string $taskType
     * @return bool
     */
    public function hasCompletedTaskToday($taskType)
    {
        return $this->taskProgress()
            ->where('task_type', $taskType)
            ->whereDate('completion_date', today())
            ->exists();
    }

    /**
     * Get the number of attempts a user has made today for a specific task type.
     *
     * @param string $taskType
     * @return int
     */
    public function getTodayTaskAttempts($taskType)
    {
        $progress = $this->taskProgress()
            ->where('task_type', $taskType)
            ->whereDate('completion_date', today())
            ->first();

        return $progress ? $progress->attempts_count : 0;
    }

    /**
     * Scope: filter only active tasks.
     *
     * Usage: Task::active()->get();
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: filter only available tasks (optional helper).
     *
     * Usage: Task::available()->get();
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }


    /**
 * Determine if a user can complete this task right now.
 */
public function canUserComplete(User $user): bool
{
    $progress = $this->taskProgress()
        ->where('user_id', $user->id)
        ->whereDate('completion_date', today())
        ->first();

    // If task not available or inactive
    if (!$this->is_active || !$this->is_available) {
        return false;
    }

    // Check daily limit
    if ($progress && $progress->attempts_count >= $this->max_attempts_per_day) {
        return false;
    }

    // Check cooldown
    if ($progress && $this->cooldown_minutes > 0 && $progress->last_completed_at) {
        $lastCompleted = Carbon::parse($progress->last_completed_at);
        if ($lastCompleted->diffInMinutes(now()) < $this->cooldown_minutes) {
            return false;
        }
    }

    return true;
}

}
