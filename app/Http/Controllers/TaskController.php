<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Models\Transaction;
use App\Models\UserTaskProgress;
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
            $isCompleted = $currentAttempts >= $task->max_attempts_per_day;
            $remainingAttempts = max(0, $task->max_attempts_per_day - $currentAttempts);
            $canComplete = $task->canUserComplete($user);

            $taskData = [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
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
     * Validate task-specific completion requirements
     */
    private function validateTaskCompletion(Task $task, User $user): ?string
    {
        switch ($task->type) {
            case 'connect_twitter':
                if ($user->twitter_connected) {
                    return 'Twitter account already connected.';
                }
                break;

            case 'connect_telegram':
                if ($user->telegram_connected) {
                    return 'Telegram account already connected.';
                }
                break;

            case 'connect_wallet':
                if (!$user->hasConnectedWallet()) {
                    return 'Please connect your wallet first.';
                }
                if ($user->hasClaimedWalletBonus()) {
                    return 'Wallet bonus already claimed.';
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