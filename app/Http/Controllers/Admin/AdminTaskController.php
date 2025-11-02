<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\UserTaskProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminTaskController extends Controller
{
    /**
     * Get all tasks with statistics
     */
    public function index(Request $request)
    {
        try {
            $tasks = Task::withCount(['taskProgress as today_completions' => function($query) {
                $query->whereDate('completion_date', today());
            }])
            ->withCount(['taskProgress as total_completions'])
            ->orderBy('sort_order')
            ->get();

            $summary = [
                'total_tasks' => $tasks->count(),
                'active_tasks' => $tasks->where('is_active', true)->count(),
                'total_completions_today' => $tasks->sum('today_completions'),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'tasks' => $tasks,
                    'summary' => $summary
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tasks: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new task
     */
    public function create(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'reward_amount' => 'required|numeric|min:0.000001',
                'reward_type' => 'required|in:CMEME,USDC',
                'type' => 'required|string',
                'max_attempts_per_day' => 'required|integer|min:1',
                'cooldown_minutes' => 'required|integer|min:0',
                'sort_order' => 'required|integer',
                'is_active' => 'boolean',
                'is_available' => 'boolean',
                'metadata' => 'sometimes|array',
                'action_url' => 'nullable|url',
                'social_platform' => 'nullable|string',
                'required_content' => 'nullable|string'
            ]);

            $task = Task::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Task created successfully',
                'data' => $task
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get task statistics
     */
    public function getTaskStats(Request $request)
    {
        try {
            $period = $request->get('period', 'today');
            $stats = Task::getTaskStats($period);

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch task stats: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific task details
     */
    public function show($taskId)
    {
        try {
            $task = Task::withCount(['taskProgress as today_completions' => function($query) {
                $query->whereDate('completion_date', today());
            }])
            ->withCount(['taskProgress as total_completions'])
            ->findOrFail($taskId);

            return response()->json([
                'success' => true,
                'data' => $task
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found'
            ], 404);
        }
    }

    /**
     * Update a task
     */
    public function update(Request $request, $taskId)
    {
        try {
            $task = Task::findOrFail($taskId);

            $validated = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'sometimes|string',
                'reward_amount' => 'sometimes|numeric|min:0.000001',
                'reward_type' => 'sometimes|in:CMEME,USDC',
                'type' => 'sometimes|string',
                'max_attempts_per_day' => 'sometimes|integer|min:1',
                'cooldown_minutes' => 'sometimes|integer|min:0',
                'sort_order' => 'sometimes|integer',
                'is_active' => 'sometimes|boolean',
                'is_available' => 'sometimes|boolean',
                'metadata' => 'sometimes|array',
                'action_url' => 'nullable|url',
                'social_platform' => 'nullable|string',
                'required_content' => 'nullable|string'
            ]);

            $task->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Task updated successfully',
                'data' => $task
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a task
     */
    public function delete($taskId)
    {
        try {
            $task = Task::findOrFail($taskId);
            
            // Delete related progress records
            UserTaskProgress::where('task_id', $taskId)->delete();
            
            $task->delete();

            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle task status
     */
    public function toggleStatus($taskId)
    {
        try {
            $task = Task::findOrFail($taskId);
            $task->is_active = !$task->is_active;
            $task->save();

            return response()->json([
                'success' => true,
                'message' => 'Task status updated',
                'data' => [
                    'is_active' => $task->is_active
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle task status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get task progress statistics
     */
    public function getTaskProgress($taskId)
    {
        try {
            $task = Task::findOrFail($taskId);
            
            $progress = UserTaskProgress::where('task_id', $taskId)
                ->select([
                    DB::raw('COUNT(*) as total_attempts'),
                    DB::raw('COUNT(DISTINCT user_id) as unique_users'),
                    DB::raw('DATE(completion_date) as date')
                ])
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->limit(30)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'task' => $task,
                    'progress' => $progress
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch task progress: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset user task progress
     */
    public function resetUserTask(Request $request, $taskId)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id'
            ]);

            UserTaskProgress::where('task_id', $taskId)
                ->where('user_id', $validated['user_id'])
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'User task progress reset successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset user task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force complete task for user
     */
    public function forceCompleteTask(Request $request, $taskId)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id'
            ]);

            $task = Task::findOrFail($taskId);
            $userId = $validated['user_id'];

            // Create or update user task progress
            $userProgress = UserTaskProgress::firstOrNew([
                'user_id' => $userId,
                'task_id' => $taskId,
                'completion_date' => today()
            ]);

            $userProgress->attempts_count = $task->max_attempts_per_day;
            $userProgress->last_completed_at = now();
            $userProgress->task_type = $task->type;
            $userProgress->save();

            return response()->json([
                'success' => true,
                'message' => 'Task force completed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to force complete task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available task types
     */
    public function getTaskTypes()
    {
        $types = [
            Task::TYPE_WATCH_ADS => 'Watch Ads',
            Task::TYPE_DAILY_STREAK => 'Daily Streak',
            Task::TYPE_CONNECT_TWITTER => 'Connect Twitter',
            Task::TYPE_CONNECT_TELEGRAM => 'Connect Telegram',
            Task::TYPE_CONNECT_WALLET => 'Connect Wallet',
            Task::TYPE_FOLLOW => 'Follow',
            Task::TYPE_LIKE => 'Like',
            Task::TYPE_COMMENT => 'Comment',
            Task::TYPE_SHARE => 'Share',
            Task::TYPE_RETWEET => 'Retweet',
            Task::TYPE_JOIN_TELEGRAM => 'Join Telegram',
            Task::TYPE_JOIN_DISCORD => 'Join Discord',
            Task::TYPE_DAILY_LOGIN => 'Daily Login',
            Task::TYPE_REFER_FRIEND => 'Refer Friend',
            Task::TYPE_SOCIAL_SHARE => 'Social Share'
        ];

        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    }
}