<?php
// app/Http/Controllers/TaskController.php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class TaskController extends Controller
{
    public function getTasks(Request $request)
    {
        $user = $request->user();
        $today = Carbon::today()->toDateString();
        
        $tasks = Task::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($task) use ($user, $today) {
                $userTask = $user->taskProgress()
                    ->where('task_id', $task->id)
                    ->where('completion_date', $today)
                    ->first();

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'reward' => (float) $task->reward_amount,
                    'reward_type' => $task->reward_type,
                    'type' => $task->type,
                    'max_attempts' => $task->max_attempts_per_day,
                    'current_attempts' => $userTask ? $userTask->attempts_count : 0,
                    'is_available' => $task->is_available,
                    'is_completed' => $userTask ? $userTask->attempts_count >= $task->max_attempts_per_day : false,
                    'cooldown_minutes' => $task->cooldown_minutes,
                ];
            });

        // If no tasks in database, return default tasks
        if ($tasks->isEmpty()) {
            $tasks = collect($this->getDefaultTasks())->map(function ($task) use ($user, $today) {
                $userTask = $user->taskProgress()
                    ->where('task_type', $task['type'])
                    ->where('completion_date', $today)
                    ->first();

                return [
                    'id' => $task['id'],
                    'title' => $task['title'],
                    'description' => $task['description'],
                    'reward' => $task['reward'],
                    'reward_type' => $task['reward_type'],
                    'type' => $task['type'],
                    'max_attempts' => $task['max_attempts'],
                    'current_attempts' => $userTask ? $userTask->attempts_count : 0,
                    'is_available' => true,
                    'is_completed' => $userTask ? $userTask->attempts_count >= $task['max_attempts'] : false,
                    'cooldown_minutes' => $task['cooldown_minutes'],
                ];
            });
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Tasks retrieved successfully',
            'data' => [
                'tasks' => $tasks
            ]
        ]);
    }

    public function completeTask(Request $request, $taskId)
    {
        $user = $request->user();
        $today = Carbon::today()->toDateString();

        DB::transaction(function () use ($user, $taskId, $request, $today) {
            // For default tasks (when no database entry exists)
            if ($taskId <= 4) {
                $this->handleDefaultTask($user, $taskId, $request, $today);
                return;
            }

            // For database tasks
            $task = Task::where('id', $taskId)
                ->where('is_active', true)
                ->firstOrFail();

            $userTask = $user->taskProgress()
                ->where('task_id', $task->id)
                ->where('completion_date', $today)
                ->first();

            // Check if task can be completed
            if ($userTask && $userTask->attempts_count >= $task->max_attempts_per_day) {
                throw new \Exception('Daily limit reached for this task.');
            }

            // Check cooldown
            if ($userTask && $task->cooldown_minutes > 0) {
                $cooldownEnd = $userTask->last_completed_at->addMinutes($task->cooldown_minutes);
                if (Carbon::now()->lt($cooldownEnd)) {
                    throw new \Exception('Task is on cooldown. Please wait.');
                }
            }

            // Update or create task progress
            if ($userTask) {
                $userTask->increment('attempts_count');
                $userTask->update(['last_completed_at' => Carbon::now()]);
            } else {
                $user->taskProgress()->create([
                    'task_id' => $task->id,
                    'attempts_count' => 1,
                    'completion_date' => $today,
                    'last_completed_at' => Carbon::now(),
                ]);
            }

            // Reward user
            $this->rewardUser($user, $task->reward_amount, $task->reward_type, $task->title);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Task completed successfully!',
            'data' => [
                'user' => $user->fresh()
            ]
        ]);
    }

    private function handleDefaultTask($user, $taskId, $request, $today)
    {
        $defaultTasks = $this->getDefaultTasks();
        $task = collect($defaultTasks)->firstWhere('id', $taskId);

        if (!$task) {
            throw new \Exception('Task not found.');
        }

        $userTask = $user->taskProgress()
            ->where('task_type', $task['type'])
            ->where('completion_date', $today)
            ->first();

        // Check daily limits
        if ($userTask && $userTask->attempts_count >= $task['max_attempts']) {
            throw new \Exception('Daily limit reached for this task.');
        }

        // Handle specific task types
        switch ($task['type']) {
            case 'connect_twitter':
                if ($user->twitter_connected) {
                    throw new \Exception('Twitter account already connected.');
                }
                // Simulate Twitter connection
                $user->update(['twitter_connected' => true]);
                break;

            case 'connect_telegram':
                if ($user->telegram_connected) {
                    throw new \Exception('Telegram account already connected.');
                }
                // Simulate Telegram connection
                $user->update(['telegram_connected' => true]);
                break;
        }

        // Update task progress
        if ($userTask) {
            $userTask->increment('attempts_count');
            $userTask->update(['last_completed_at' => Carbon::now()]);
        } else {
            $user->taskProgress()->create([
                'task_type' => $task['type'],
                'attempts_count' => 1,
                'completion_date' => $today,
                'last_completed_at' => Carbon::now(),
            ]);
        }

        // Reward user
        $this->rewardUser($user, $task['reward'], $task['reward_type'], $task['title']);
    }

    private function rewardUser($user, $amount, $type, $description)
    {
        if ($type === 'CMEME') {
            $user->increment('token_balance', $amount);
            
            // Create transaction record
            Transaction::create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_EARNING,
                'amount' => $amount,
                'description' => "Task reward: {$description}",
                'metadata' => [
                    'reward_type' => 'task',
                    'task_description' => $description,
                ],
            ]);
        } elseif ($type === 'USDC') {
            $user->increment('usdc_balance', $amount);
        }
    }

    private function getDefaultTasks()
    {
        return [
            [
                'id' => 1,
                'title' => 'Watch Ads',
                'description' => 'Watch ads to earn CMEME tokens. Up to 60 times daily.',
                'reward' => 0.05,
                'reward_type' => 'CMEME',
                'type' => 'watch_ads',
                'max_attempts' => 60,
                'cooldown_minutes' => 0,
            ],
            [
                'id' => 2,
                'title' => 'Daily Streak Claim',
                'description' => 'Claim your daily streak bonus.',
                'reward' => 0.5,
                'reward_type' => 'CMEME',
                'type' => 'daily_streak',
                'max_attempts' => 1,
                'cooldown_minutes' => 1440, // 24 hours
            ],
            [
                'id' => 3,
                'title' => 'Connect X (Twitter) Account',
                'description' => 'Connect your X (Twitter) account to earn rewards.',
                'reward' => 5,
                'reward_type' => 'CMEME',
                'type' => 'connect_twitter',
                'max_attempts' => 1,
                'cooldown_minutes' => 0,
            ],
            [
                'id' => 4,
                'title' => 'Connect Telegram Account',
                'description' => 'Connect your Telegram account to earn rewards.',
                'reward' => 5,
                'reward_type' => 'CMEME',
                'type' => 'connect_telegram',
                'max_attempts' => 1,
                'cooldown_minutes' => 0,
            ],
        ];
    }
}