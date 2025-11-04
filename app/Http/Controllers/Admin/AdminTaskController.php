<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\UserTaskProgress;
use App\Models\User;
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
                'engagement_tasks' => $tasks->where(function($task) {
                    return in_array($task->type, [
                        Task::TYPE_FOLLOW_X,
                        Task::TYPE_LIKE_X,
                        Task::TYPE_RETWEET_X,
                        Task::TYPE_COMMENT_X,
                        Task::TYPE_QUOTE_TWEET,
                        Task::TYPE_JOIN_TWITTER_SPACE,
                    ]);
                })->count(),
                'total_completions_today' => $tasks->sum('today_completions'),
                'engagement_completions_today' => $tasks->where(function($task) {
                    return in_array($task->type, [
                        Task::TYPE_FOLLOW_X,
                        Task::TYPE_LIKE_X,
                        Task::TYPE_RETWEET_X,
                        Task::TYPE_COMMENT_X,
                        Task::TYPE_QUOTE_TWEET,
                        Task::TYPE_JOIN_TWITTER_SPACE,
                    ]);
                })->sum('today_completions'),
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
            
            $stats = [
                'total_tasks' => Task::count(),
                'active_tasks' => Task::active()->count(),
                'engagement_tasks' => Task::whereIn('type', [
                    Task::TYPE_FOLLOW_X,
                    Task::TYPE_LIKE_X,
                    Task::TYPE_RETWEET_X,
                    Task::TYPE_COMMENT_X,
                    Task::TYPE_QUOTE_TWEET,
                    Task::TYPE_JOIN_TWITTER_SPACE,
                ])->count(),
                'total_completions_today' => UserTaskProgress::whereDate('completion_date', today())->count(),
                'engagement_completions_today' => UserTaskProgress::whereDate('completion_date', today())
                    ->whereHas('task', function($query) {
                        $query->whereIn('type', [
                            Task::TYPE_FOLLOW_X,
                            Task::TYPE_LIKE_X,
                            Task::TYPE_RETWEET_X,
                            Task::TYPE_COMMENT_X,
                            Task::TYPE_QUOTE_TWEET,
                            Task::TYPE_JOIN_TWITTER_SPACE,
                        ]);
                    })->count(),
                'total_rewards_distributed' => UserTaskProgress::whereHas('task')
                    ->with('task')
                    ->get()
                    ->sum(function($progress) {
                        return $progress->attempts_count * $progress->task->reward_amount;
                    }),
                'engagement_rewards_distributed' => UserTaskProgress::whereHas('task', function($query) {
                        $query->whereIn('type', [
                            Task::TYPE_FOLLOW_X,
                            Task::TYPE_LIKE_X,
                            Task::TYPE_RETWEET_X,
                            Task::TYPE_COMMENT_X,
                            Task::TYPE_QUOTE_TWEET,
                            Task::TYPE_JOIN_TWITTER_SPACE,
                        ]);
                    })
                    ->with('task')
                    ->get()
                    ->sum(function($progress) {
                        return $progress->attempts_count * $progress->task->reward_amount;
                    }),
            ];

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
            ->with(['taskProgress.user:id,username,email'])
            ->findOrFail($taskId);

            // Add engagement statistics for engagement tasks
            if ($task->isEngagementTask()) {
                $task->screenshot_completions = UserTaskProgress::where('task_id', $taskId)
                    ->whereNotNull('proof_data->screenshot_url')
                    ->count();
                
                $task->recent_completions = UserTaskProgress::where('task_id', $taskId)
                    ->with('user:id,username,email')
                    ->orderBy('last_completed_at', 'desc')
                    ->limit(10)
                    ->get();
            }

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
            
            // Check if task has completions
            if (UserTaskProgress::where('task_id', $taskId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete task that has user completions. Consider deactivating it instead.'
                ], 422);
            }
            
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
                ->with('user:id,username,email')
                ->select([
                    'user_task_progress.*',
                    DB::raw('DATE(completion_date) as date')
                ])
                ->orderBy('last_completed_at', 'desc')
                ->limit(50)
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

            $deletedCount = UserTaskProgress::where('task_id', $taskId)
                ->where('user_id', $validated['user_id'])
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'User task progress reset successfully',
                'data' => [
                    'deleted_records' => $deletedCount
                ]
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

            // Check if user already completed this task today
            $existingProgress = UserTaskProgress::where('user_id', $userId)
                ->where('task_id', $taskId)
                ->whereDate('completion_date', today())
                ->first();

            if ($existingProgress) {
                return response()->json([
                    'success' => false,
                    'message' => 'User has already completed this task today'
                ], 422);
            }

            // Create or update user task progress
            $userProgress = UserTaskProgress::create([
                'user_id' => $userId,
                'task_id' => $taskId,
                'task_type' => $task->type,
                'attempts_count' => $task->max_attempts_per_day,
                'completion_date' => today(),
                'last_completed_at' => now(),
                'metadata' => [
                    'completed_by' => 'admin_force_complete',
                    'admin_action' => true
                ]
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task force completed successfully',
                'data' => [
                    'progress' => $userProgress
                ]
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
            // New Engagement Task Types
            Task::TYPE_FOLLOW_X => 'Follow X Account',
            Task::TYPE_LIKE_X => 'Like X Post',
            Task::TYPE_RETWEET_X => 'Retweet X Post',
            Task::TYPE_COMMENT_X => 'Comment on X Post',
            Task::TYPE_QUOTE_TWEET => 'Quote Tweet',
            Task::TYPE_JOIN_TWITTER_SPACE => 'Join Twitter Space',
        ];

        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    }

    /**
     * Get engagement tasks with detailed statistics
     */
    public function getEngagementTasks(Request $request)
    {
        try {
            $engagementTasks = Task::whereIn('type', [
                Task::TYPE_FOLLOW_X,
                Task::TYPE_LIKE_X,
                Task::TYPE_RETWEET_X,
                Task::TYPE_COMMENT_X,
                Task::TYPE_QUOTE_TWEET,
                Task::TYPE_JOIN_TWITTER_SPACE,
            ])
            ->withCount(['taskProgress as today_completions' => function($query) {
                $query->whereDate('completion_date', today());
            }])
            ->withCount(['taskProgress as total_completions'])
            ->withCount(['taskProgress as screenshot_completions' => function($query) {
                $query->whereNotNull('proof_data->screenshot_url');
            }])
            ->orderBy('sort_order')
            ->get();

            $stats = [
                'total_engagement_tasks' => $engagementTasks->count(),
                'active_engagement_tasks' => $engagementTasks->where('is_active', true)->count(),
                'total_completions_today' => $engagementTasks->sum('today_completions'),
                'total_completions_all_time' => $engagementTasks->sum('total_completions'),
                'screenshot_completions' => $engagementTasks->sum('screenshot_completions'),
                'completion_rate' => $engagementTasks->count() > 0 ? 
                    round(($engagementTasks->sum('total_completions') / ($engagementTasks->count() * 100)) * 100, 2) : 0,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'tasks' => $engagementTasks,
                    'stats' => $stats
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch engagement tasks: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get engagement task completions with proof
     */
    public function getEngagementCompletions(Request $request, $taskId = null)
    {
        try {
            $query = UserTaskProgress::with(['user:id,username,email', 'task:id,title,type'])
                ->whereHas('task', function($query) {
                    $query->whereIn('type', [
                        Task::TYPE_FOLLOW_X,
                        Task::TYPE_LIKE_X,
                        Task::TYPE_RETWEET_X,
                        Task::TYPE_COMMENT_X,
                        Task::TYPE_QUOTE_TWEET,
                        Task::TYPE_JOIN_TWITTER_SPACE,
                    ]);
                })
                ->whereNotNull('proof_data')
                ->orderBy('last_completed_at', 'desc');

            if ($taskId) {
                $query->where('task_id', $taskId);
            }

            // Add filters
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('completion_date', '>=', $request->date_from);
            }

            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('completion_date', '<=', $request->date_to);
            }

            if ($request->has('has_screenshot') && $request->has_screenshot) {
                $query->whereNotNull('proof_data->screenshot_url');
            }

            $completions = $query->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => [
                    'completions' => $completions->items(),
                    'pagination' => [
                        'current_page' => $completions->currentPage(),
                        'last_page' => $completions->lastPage(),
                        'per_page' => $completions->perPage(),
                        'total' => $completions->total(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch engagement completions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get task completion analytics
     */
    public function getTaskAnalytics(Request $request)
    {
        try {
            $period = $request->get('period', '7days'); // 7days, 30days, 90days
            
            switch ($period) {
                case '30days':
                    $startDate = now()->subDays(30);
                    break;
                case '90days':
                    $startDate = now()->subDays(90);
                    break;
                default:
                    $startDate = now()->subDays(7);
            }

            // Total completions over time
            $completionsOverTime = UserTaskProgress::where('completion_date', '>=', $startDate)
                ->selectRaw('DATE(completion_date) as date, COUNT(*) as completions')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            // Task type distribution
            $taskTypeDistribution = UserTaskProgress::where('completion_date', '>=', $startDate)
                ->join('tasks', 'user_task_progress.task_id', '=', 'tasks.id')
                ->selectRaw('tasks.type, COUNT(*) as completions')
                ->groupBy('tasks.type')
                ->orderBy('completions', 'desc')
                ->get();

            // Top performing tasks
            $topTasks = UserTaskProgress::where('completion_date', '>=', $startDate)
                ->join('tasks', 'user_task_progress.task_id', '=', 'tasks.id')
                ->selectRaw('tasks.id, tasks.title, tasks.type, COUNT(*) as completions, SUM(tasks.reward_amount) as total_rewards')
                ->groupBy('tasks.id', 'tasks.title', 'tasks.type')
                ->orderBy('completions', 'desc')
                ->limit(10)
                ->get();

            // User engagement stats
            $userEngagement = UserTaskProgress::where('completion_date', '>=', $startDate)
                ->selectRaw('user_id, COUNT(*) as task_count')
                ->groupBy('user_id')
                ->selectRaw('COUNT(*) as task_count')
                ->get();

            $averageTasksPerUser = $userEngagement->count() > 0 ? $userEngagement->sum('task_count') / $userEngagement->count() : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => $period,
                    'completions_over_time' => $completionsOverTime,
                    'task_type_distribution' => $taskTypeDistribution,
                    'top_tasks' => $topTasks,
                    'user_engagement' => [
                        'total_users' => $userEngagement->count(),
                        'total_completions' => $userEngagement->sum('task_count'),
                        'average_tasks_per_user' => round($averageTasksPerUser, 2),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch task analytics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update tasks
     */
    public function bulkUpdate(Request $request)
    {
        try {
            $validated = $request->validate([
                'task_ids' => 'required|array',
                'task_ids.*' => 'exists:tasks,id',
                'updates' => 'required|array',
                'updates.is_active' => 'sometimes|boolean',
                'updates.is_available' => 'sometimes|boolean',
                'updates.reward_amount' => 'sometimes|numeric|min:0.000001',
            ]);

            $updatedCount = Task::whereIn('id', $validated['task_ids'])
                ->update($validated['updates']);

            return response()->json([
                'success' => true,
                'message' => 'Tasks updated successfully',
                'data' => [
                    'updated_count' => $updatedCount
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk update tasks: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user task history
     */
    public function getUserTaskHistory(Request $request, $userId)
    {
        try {
            $user = User::findOrFail($userId);

            $completions = UserTaskProgress::where('user_id', $userId)
                ->with('task:id,title,type,reward_amount,reward_type')
                ->orderBy('last_completed_at', 'desc')
                ->paginate($request->get('per_page', 20));

            $userStats = [
                'total_tasks_completed' => UserTaskProgress::where('user_id', $userId)->count(),
                'total_earned' => UserTaskProgress::where('user_id', $userId)
                    ->with('task')
                    ->get()
                    ->sum(function($progress) {
                        return $progress->attempts_count * $progress->task->reward_amount;
                    }),
                'engagement_tasks_completed' => UserTaskProgress::where('user_id', $userId)
                    ->whereHas('task', function($query) {
                        $query->whereIn('type', [
                            Task::TYPE_FOLLOW_X,
                            Task::TYPE_LIKE_X,
                            Task::TYPE_RETWEET_X,
                            Task::TYPE_COMMENT_X,
                            Task::TYPE_QUOTE_TWEET,
                            Task::TYPE_JOIN_TWITTER_SPACE,
                        ]);
                    })->count(),
                'last_activity' => UserTaskProgress::where('user_id', $userId)
                    ->latest('last_completed_at')
                    ->value('last_completed_at'),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'stats' => $userStats,
                    'completions' => $completions->items(),
                    'pagination' => [
                        'current_page' => $completions->currentPage(),
                        'last_page' => $completions->lastPage(),
                        'per_page' => $completions->perPage(),
                        'total' => $completions->total(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user task history: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export task completions
     */
    public function exportCompletions(Request $request)
    {
        try {
            $query = UserTaskProgress::with(['user:id,username,email', 'task:id,title,type,reward_amount'])
                ->orderBy('last_completed_at', 'desc');

            // Apply filters
            if ($request->has('task_id') && $request->task_id) {
                $query->where('task_id', $request->task_id);
            }

            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('completion_date', '>=', $request->date_from);
            }

            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('completion_date', '<=', $request->date_to);
            }

            if ($request->has('task_type') && $request->task_type) {
                $query->whereHas('task', function($q) use ($request) {
                    $q->where('type', $request->task_type);
                });
            }

            $completions = $query->get();

            $exportData = $completions->map(function($completion) {
                return [
                    'id' => $completion->id,
                    'user' => $completion->user->username,
                    'email' => $completion->user->email,
                    'task' => $completion->task->title,
                    'task_type' => $completion->task->type,
                    'attempts' => $completion->attempts_count,
                    'reward_amount' => $completion->task->reward_amount,
                    'total_reward' => $completion->attempts_count * $completion->task->reward_amount,
                    'completion_date' => $completion->completion_date,
                    'last_completed' => $completion->last_completed_at,
                    'has_screenshot' => !empty($completion->proof_data['screenshot_url']) ? 'Yes' : 'No',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $exportData,
                'metadata' => [
                    'total_records' => $exportData->count(),
                    'export_date' => now()->toISOString(),
                    'filters_applied' => $request->all(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export completions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate task configuration
     */
  /**
 * Validate task configuration
 */
public function validateTaskConfig(Request $request)
{
    try {
        $validated = $request->validate([
            'type' => 'required|string',
            'reward_amount' => 'required|numeric|min:0.000001',
            'max_attempts_per_day' => 'required|integer|min:1',
            'cooldown_minutes' => 'required|integer|min:0',
        ]);

        $warnings = [];
        $recommendations = [];

        // Check for engagement task specific validations
        $engagementTypes = [
            Task::TYPE_FOLLOW_X,
            Task::TYPE_LIKE_X,
            Task::TYPE_RETWEET_X,
            Task::TYPE_COMMENT_X,
            Task::TYPE_QUOTE_TWEET,
            Task::TYPE_JOIN_TWITTER_SPACE,
        ];

        if (in_array($validated['type'], $engagementTypes)) {
            if ($validated['max_attempts_per_day'] > 1) {
                $warnings[] = 'Engagement tasks typically have max 1 attempt per day.';
                $recommendations[] = 'Consider setting max_attempts_per_day to 1 for engagement tasks.';
            }

            if ($validated['reward_amount'] > 10) {
                $warnings[] = 'High reward amount for engagement task.';
                $recommendations[] = 'Typical engagement task rewards range from 0.5 to 5 CMEME.';
            }
        }

        // Check for watch ads task validations
        if ($validated['type'] === Task::TYPE_WATCH_ADS) {
            if ($validated['max_attempts_per_day'] < 10) {
                $warnings[] = 'Low max attempts for watch ads task.';
                $recommendations[] = 'Watch ads tasks typically allow 10–60 attempts per day.';
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'valid' => empty($warnings),
                'warnings' => $warnings,
                'recommendations' => $recommendations,
                'validated_config' => $validated,
            ],
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to validate task configuration: ' . $e->getMessage(),
        ], 500);
    }
}

}