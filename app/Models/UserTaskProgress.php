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
    ];

    protected $casts = [
        'completion_date' => 'date',
        'last_completed_at' => 'datetime',
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
}