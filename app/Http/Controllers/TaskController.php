<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Models\Transaction;
use App\Models\UserTaskProgress;
use App\Models\UserSocialHandle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TaskController extends Controller
{
    public function getTasks(Request $request)
    {
        $user = $request->user();
        
        Log::info('Fetching tasks for user: ' . $user->id);
        
        // Debug: Check if tasks exist in database
        $taskCount = Task::count();
        Log::info('Total tasks in database: ' . $taskCount);
        
        $tasksFromDB = Task::active()
            ->get();
            
        Log::info('Tasks retrieved from database: ' . $tasksFromDB->count());
        
        if ($tasksFromDB->isEmpty()) {
            Log::warning('No tasks found in database!');
        }

        $tasks = $tasksFromDB->map(function ($task) use ($user) {
            $userTask = $task->taskProgress()
                ->where('user_id', $user->id)
                ->whereDate('completion_date', today())
                ->first();

            $currentAttempts = $userTask ? $userTask->attempts_count : 0;
            
            // Special handling for wallet task - it's one-time completion
            if ($task->type === 'connect_wallet') {
                $walletTaskCompleted = UserTaskProgress::where('user_id', $user->id)
                    ->where('task_type', 'connect_wallet')
                    ->exists();
                $isCompleted = $walletTaskCompleted;
                $remainingAttempts = $walletTaskCompleted ? 0 : 1;
            } else {
                $isCompleted = $currentAttempts >= $task->max_attempts_per_day;
                $remainingAttempts = max(0, $task->max_attempts_per_day - $currentAttempts);
            }
            
            $canComplete = $task->canUserComplete($user);

            // Update description for daily streak task to show current streak
            $description = $task->description;
            if ($task->type === 'daily_streak') {
                $description = "Claim your daily streak bonus. Current streak: {$user->mining_streak} days";
            }

            // Check if social tasks are already completed
            if (in_array($task->type, ['connect_twitter', 'connect_telegram'])) {
                $socialHandle = UserSocialHandle::where('user_id', $user->id)
                    ->where('platform', $task->type === 'connect_twitter' ? 'twitter' : 'telegram')
                    ->first();
                
                if ($socialHandle) {
                    $isCompleted = true;
                    $canComplete = false;
                }
            }

            $taskData = [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $description,
                'reward' => (float) $task->reward_amount,
                'reward_type' => $task->reward_type,
                'type' => $task->type,
                'max_attempts' => $task->max_attempts_per_day,
                'current_attempts' => $currentAttempts,
                'remaining_attempts' => $remainingAttempts,
                'is_available' => $task->is_available,
                'is_completed' => $isCompleted,
                'can_complete' => $canComplete,
                'cooldown_minutes' => $task->cooldown_minutes,
                'sort_order' => $task->sort_order,
                'action_url' => $task->action_url,
                'social_platform' => $task->social_platform,
                'required_content' => $task->required_content,
                'metadata' => $task->metadata,
            ];
            
            Log::info('Task processed: ' . $task->title, $taskData);
            
            return $taskData;
        });

        Log::info('Final tasks array count: ' . $tasks->count());

        return response()->json([
            'status' => 'success',
            'message' => 'Tasks retrieved successfully',
            'data' => [
                'tasks' => $tasks,
                'debug' => [ // Add debug info temporarily
                    'total_tasks_in_db' => $taskCount,
                    'tasks_retrieved' => $tasksFromDB->count(),
                    'tasks_processed' => $tasks->count()
                ]
            ]
        ]);
    }

    public function completeTask(Request $request, $taskId)
    {
        $user = $request->user();

        try {
            DB::transaction(function () use ($user, $taskId, $request) {
                $task = Task::active()
                    ->available()
                    ->findOrFail($taskId);

                // Check if user can complete the task
                if (!$task->canUserComplete($user)) {
                    throw new \Exception('You cannot complete this task at this time. Check daily limits or cooldown.');
                }

                // Handle specific task types with additional validation
                $validationError = $this->validateTaskCompletion($task, $user);
                if ($validationError) {
                    throw new \Exception($validationError);
                }

                // Handle social media tasks
                if (in_array($task->type, ['connect_twitter', 'connect_telegram'])) {
                    $socialHandle = $request->input('social_handle');
                    if (!$socialHandle) {
                        throw new \Exception('Social media handle is required for this task.');
                    }
                    
                    $this->saveSocialHandle($user, $task->type, $socialHandle);
                }

                // Handle engagement tasks
                if (in_array($task->type, ['follow', 'like', 'comment', 'share', 'retweet', 'join_telegram', 'join_discord'])) {
                    $proof = $request->input('proof');
                    if (!$proof && $task->required_content) {
                        throw new \Exception('Proof of completion is required for this task.');
                    }
                }

                // For wallet task, check if already completed (one-time)
                if ($task->type === 'connect_wallet') {
                    $walletTaskCompleted = UserTaskProgress::where('user_id', $user->id)
                        ->where('task_type', 'connect_wallet')
                        ->exists();
                        
                    if ($walletTaskCompleted) {
                        throw new \Exception('Wallet connection task already completed.');
                    }
                }

                // Get or create today's progress
                $userTask = UserTaskProgress::where('user_id', $user->id)
                    ->where('task_id', $task->id)
                    ->whereDate('completion_date', today())
                    ->first();

                if ($userTask) {
                    // Update existing progress
                    $userTask->increment('attempts_count');
                    $userTask->update(['last_completed_at' => now()]);
                } else {
                    // Create new progress
                    $userTask = UserTaskProgress::create([
                        'user_id' => $user->id,
                        'task_id' => $task->id,
                        'task_type' => $task->type,
                        'attempts_count' => 1,
                        'completion_date' => today(),
                        'last_completed_at' => now(),
                    ]);
                }

                // Reward user
                $this->rewardUser($user, $task->reward_amount, $task->reward_type, $task->title);

                // Update daily streak for daily_streak task
                if ($task->type === 'daily_streak') {
                    $this->updateDailyStreak($user);
                }

                // Update user specific fields for certain tasks
                $this->updateUserForTask($task, $user);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Task completed successfully!',
                'data' => [
                    'user' => $user->fresh()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Save social media handle for user
     */
    private function saveSocialHandle(User $user, string $taskType, string $handle): void
    {
        $platform = $taskType === 'connect_twitter' ? 'twitter' : 'telegram';
        
        UserSocialHandle::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $platform,
            ],
            [
                'handle' => $handle,
                'connected_at' => now(),
            ]
        );
    }

    /**
     * Validate task-specific completion requirements
     */
    private function validateTaskCompletion(Task $task, User $user): ?string
    {
        switch ($task->type) {
            case 'connect_twitter':
                $twitterHandle = UserSocialHandle::where('user_id', $user->id)
                    ->where('platform', 'twitter')
                    ->first();
                if ($twitterHandle) {
                    return 'Twitter account already connected.';
                }
                break;

            case 'connect_telegram':
                $telegramHandle = UserSocialHandle::where('user_id', $user->id)
                    ->where('platform', 'telegram')
                    ->first();
                if ($telegramHandle) {
                    return 'Telegram account already connected.';
                }
                break;

            case 'connect_wallet':
                // For wallet task, check if user has already completed it (one-time)
                $walletTaskCompleted = UserTaskProgress::where('user_id', $user->id)
                    ->where('task_type', 'connect_wallet')
                    ->exists();
                    
                if ($walletTaskCompleted) {
                    return 'Wallet connection task already completed.';
                }
                
                if (!$user->hasConnectedWallet()) {
                    return 'Please connect your wallet first.';
                }
                break;
        }

        return null;
    }

    /**
     * Update user fields for specific tasks
     */
    private function updateUserForTask(Task $task, User $user): void
    {
        switch ($task->type) {
            case 'connect_twitter':
                $user->update(['twitter_connected' => true]);
                break;

            case 'connect_telegram':
                $user->update(['telegram_connected' => true]);
                break;

            case 'connect_wallet':
                // Wallet bonus is handled in WalletController
                break;
        }
    }

    /**
     * Update daily streak for user
     */
    private function updateDailyStreak(User $user): void
    {
        $today = today();
        $yesterday = today()->subDay();
        
        // Check if user completed any task yesterday
        $yesterdayCompleted = UserTaskProgress::where('user_id', $user->id)
            ->whereDate('completion_date', $yesterday)
            ->exists();
            
        // Get current streak
        $currentStreak = $user->mining_streak ?: 0;
        
        if ($yesterdayCompleted) {
            // Continue streak
            $newStreak = $currentStreak + 1;
        } else {
            // Reset streak if missed yesterday
            $newStreak = 1;
        }
        
        // Update user streak
        $user->update(['mining_streak' => $newStreak]);
    }

    /**
     * Reward user for completing task
     */
    private function rewardUser(User $user, $amount, $type, $description): void
    {
        if ($type === 'CMEME') {
            $user->increment('token_balance', $amount);
            
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

    /**
     * Get user task statistics
     */
    public function getTaskStats(Request $request)
    {
        $user = $request->user();
        $today = today();

        $completedToday = UserTaskProgress::where('user_id', $user->id)
            ->whereDate('completion_date', $today)
            ->count();

        $totalEarnedToday = UserTaskProgress::where('user_id', $user->id)
            ->whereDate('completion_date', $today)
            ->with('task')
            ->get()
            ->sum(function ($progress) {
                return $progress->attempts_count * $progress->task->reward_amount;
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'completed_today' => $completedToday,
                'total_earned_today' => $totalEarnedToday,
                'date' => $today->toDateString(),
            ]
        ]);
    }
}