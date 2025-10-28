<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTaskProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AdminTaskController extends Controller
{
    public function index(Request $request)
    {
        $tasks = Task::withCount(['taskProgress as today_completions' => function($query) {
                $query->whereDate('completion_date', today());
            }])
            ->with(['taskProgress' => function($query) {
                $query->whereDate('completion_date', today())
                      ->with('user');
            }])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'tasks' => $tasks,
                'summary' => [
                    'total_tasks' => $tasks->count(),
                    'active_tasks' => $tasks->where('is_active', true)->count(),
                    'total_completions_today' => $tasks->sum('today_completions'),
                ]
            ]
        ]);
    }

    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'reward_amount' => 'required|numeric|min:0',
            'reward_type' => 'required|string|in:CMEME,USDC',
            'type' => 'required|string',
            'max_attempts_per_day' => 'required|integer|min:1',
            'cooldown_minutes' => 'required|integer|min:0',
            'sort_order' => 'required|integer',
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $task = Task::create($request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'Task created successfully',
                'data' => ['task' => $task]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create task: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($taskId)
    {
        $task = Task::find($taskId);

        if (!$task) {
            return response()->json([
                'status' => 'error',
                'message' => 'Task not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => ['task' => $task]
        ]);
    }

    public function update(Request $request, $taskId)
    {
        $task = Task::find($taskId);

        if (!$task) {
            return response()->json([
                'status' => 'error',
                'message' => 'Task not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'reward_amount' => 'sometimes|required|numeric|min:0',
            'reward_type' => 'sometimes|required|string|in:CMEME,USDC',
            'type' => 'sometimes|required|string',
            'max_attempts_per_day' => 'sometimes|required|integer|min:1',
            'cooldown_minutes' => 'sometimes|required|integer|min:0',
            'sort_order' => 'sometimes|required|integer',
            'is_active' => 'sometimes|boolean',
            'is_available' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $task->update($request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'Task updated successfully',
                'data' => ['task' => $task->fresh()]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update task: ' . $e->getMessage()
            ], 500);
        }
    }

    public function delete($taskId)
    {
        $task = Task::find($taskId);

        if (!$task) {
            return response()->json([
                'status' => 'error',
                'message' => 'Task not found'
            ], 404);
        }

        try {
            // Check if there are any user progress records
            $progressCount = UserTaskProgress::where('task_id', $taskId)->count();
            
            if ($progressCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete task. There are user progress records associated with this task.'
                ], 400);
            }

            $task->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Task deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete task: ' . $e->getMessage()
            ], 500);
        }
    }

    public function toggleStatus($taskId)
    {
        $task = Task::find($taskId);

        if (!$task) {
            return response()->json([
                'status' => 'error',
                'message' => 'Task not found'
            ], 404);
        }

        try {
            $task->update([
                'is_active' => !$task->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Task status updated successfully',
                'data' => [
                    'task' => $task->fresh(),
                    'new_status' => $task->is_active ? 'active' : 'inactive'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update task status: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getUserTasks(Request $request, $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }
        
        $tasks = Task::active()
            ->with(['taskProgress' => function($query) use ($userId) {
                $query->where('user_id', $userId)
                      ->whereDate('completion_date', today());
            }])
            ->get()
            ->map(function ($task) use ($user) {
                $userTask = $task->taskProgress->first();
                $currentAttempts = $userTask ? $userTask->attempts_count : 0;
                
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'reward_amount' => (float) $task->reward_amount,
                    'reward_type' => $task->reward_type,
                    'type' => $task->type,
                    'max_attempts_per_day' => $task->max_attempts_per_day,
                    'current_attempts' => $currentAttempts,
                    'remaining_attempts' => max(0, $task->max_attempts_per_day - $currentAttempts),
                    'is_completed' => $currentAttempts >= $task->max_attempts_per_day,
                    'last_completed_at' => $userTask ? $userTask->last_completed_at : null,
                    'can_complete' => $task->canUserComplete($user),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user->only(['id', 'username', 'email', 'token_balance', 'mining_streak']),
                'tasks' => $tasks
            ]
        ]);
    }

    public function resetUserTask(Request $request, $taskId)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);

        $userId = $request->user_id;

        $deleted = UserTaskProgress::where('user_id', $userId)
            ->where('task_id', $taskId)
            ->whereDate('completion_date', today())
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'User task progress reset successfully',
            'data' => [
                'reset' => $deleted
            ]
        ]);
    }

    public function forceCompleteTask(Request $request, $taskId)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);

        $userId = $request->user_id;
        $task = Task::findOrFail($taskId);

        DB::transaction(function () use ($userId, $task) {
            // Get or create today's progress
            $userTask = UserTaskProgress::where('user_id', $userId)
                ->where('task_id', $task->id)
                ->whereDate('completion_date', today())
                ->first();

            if ($userTask) {
                $userTask->increment('attempts_count');
                $userTask->update(['last_completed_at' => now()]);
            } else {
                $userTask = UserTaskProgress::create([
                    'user_id' => $userId,
                    'task_id' => $task->id,
                    'task_type' => $task->type,
                    'attempts_count' => 1,
                    'completion_date' => today(),
                    'last_completed_at' => now(),
                ]);
            }

            // Reward user
            $user = User::find($userId);
            if ($task->reward_type === 'CMEME') {
                $user->increment('token_balance', $task->reward_amount);
            } elseif ($task->reward_type === 'USDC') {
                $user->increment('usdc_balance', $task->reward_amount);
            }

            // Update streak for daily streak task
            if ($task->type === 'daily_streak') {
                $this->updateUserStreak($user);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Task completed successfully for user',
            'data' => [
                'user' => User::find($userId)->fresh()
            ]
        ]);
    }

    public function getTaskStats(Request $request)
    {
        $period = $request->get('period', 'today'); // today, week, month
        
        $query = UserTaskProgress::query();
        
        switch ($period) {
            case 'week':
                $query->whereBetween('completion_date', [now()->startOfWeek(), now()->endOfWeek()]);
                break;
            case 'month':
                $query->whereBetween('completion_date', [now()->startOfMonth(), now()->endOfMonth()]);
                break;
            default: // today
                $query->whereDate('completion_date', today());
                break;
        }

        $stats = $query->select(
            DB::raw('COUNT(*) as total_completions'),
            DB::raw('COUNT(DISTINCT user_id) as unique_users'),
            DB::raw('SUM(attempts_count) as total_attempts')
        )->first();

        $topTasks = Task::withCount(['taskProgress as completions_count' => function($query) use ($period) {
                switch ($period) {
                    case 'week':
                        $query->whereBetween('completion_date', [now()->startOfWeek(), now()->endOfWeek()]);
                        break;
                    case 'month':
                        $query->whereBetween('completion_date', [now()->startOfMonth(), now()->endOfMonth()]);
                        break;
                    default:
                        $query->whereDate('completion_date', today());
                        break;
                }
            }])
            ->orderBy('completions_count', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => $period,
                'stats' => $stats,
                'top_tasks' => $topTasks,
                'date_range' => [
                    'start' => $period === 'today' ? today() : 
                              ($period === 'week' ? now()->startOfWeek() : now()->startOfMonth()),
                    'end' => now()
                ]
            ]
        ]);
    }

    private function updateUserStreak(User $user)
    {
        $today = today();
        $yesterday = today()->subDay();
        
        $yesterdayCompleted = UserTaskProgress::where('user_id', $user->id)
            ->whereDate('completion_date', $yesterday)
            ->exists();
            
        $currentStreak = $user->mining_streak ?: 0;
        
        if ($yesterdayCompleted) {
            $newStreak = $currentStreak + 1;
        } else {
            $newStreak = 1;
        }
        
        $user->update(['mining_streak' => $newStreak]);
    }
}