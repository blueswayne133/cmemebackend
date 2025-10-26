<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\KycVerification;
use App\Models\Transaction;
use App\Models\P2PTrade;
use App\Models\WalletDetail;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Add this import

class DashboardController extends Controller
{
    public function getStats()
    {
        try {
            $totalUsers = User::count();
            
            // Count active users based on recent activity (last 30 days)
            $activeUsers = $this->getActiveUsersCount();
            
            $newUsersToday = User::whereDate('created_at', today())->count();
            
            $pendingKyc = KycVerification::where('status', 'pending')->count();
            $verifiedKyc = KycVerification::where('status', 'verified')->count();
            
            $totalVolume = Transaction::where('amount', '>', 0)->sum('amount');
            $todayVolume = Transaction::whereDate('created_at', today())->sum('amount');
            
            // Safely get P2P trade counts
            $activeTrades = $this->getActiveTradesCount();
            $completedTrades = $this->getCompletedTradesCount();
            
            $connectedWallets = WalletDetail::where('is_connected', true)->count();
            
            // Get active tasks count
            $activeTasks = Task::count();

            // Weekly user growth
            $weeklyGrowth = User::select([
                    DB::raw('COUNT(*) as count'),
                    DB::raw('DATE(created_at) as date')
                ])
                ->where('created_at', '>=', now()->subDays(7))
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'users' => [
                        'total' => $totalUsers,
                        'active' => $activeUsers,
                        'new_today' => $newUsersToday,
                        'weekly_growth' => $weeklyGrowth
                    ],
                    'kyc' => [
                        'pending' => $pendingKyc,
                        'verified' => $verifiedKyc
                    ],
                    'transactions' => [
                        'total_volume' => $totalVolume,
                        'today_volume' => $todayVolume
                    ],
                    'trading' => [
                        'active_trades' => $activeTrades,
                        'completed_trades' => $completedTrades
                    ],
                    'wallets' => [
                        'connected' => $connectedWallets
                    ],
                    'tasks' => [
                        'active' => $activeTasks
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Dashboard stats error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Count active users based on recent activity
     */
    private function getActiveUsersCount()
    {
        try {
            return Transaction::where('created_at', '>=', now()->subDays(30))
                ->distinct('user_id')
                ->count('user_id');
        } catch (\Exception $e) {
            Log::error('Error counting active users: ' . $e->getMessage());
            return User::count();
        }
    }

    /**
     * Safely get active trades count
     */
    private function getActiveTradesCount()
    {
        try {
            return P2PTrade::whereIn('status', ['active', 'processing'])->count();
        } catch (\Exception $e) {
            Log::error('Error counting active trades: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Safely get completed trades count
     */
    private function getCompletedTradesCount()
    {
        try {
            return P2PTrade::where('status', 'completed')->count();
        } catch (\Exception $e) {
            Log::error('Error counting completed trades: ' . $e->getMessage());
            return 0;
        }
    }

    public function getRecentActivity()
    {
        try {
            // Get recent users with correct fields
            $recentUsers = User::orderBy('created_at', 'desc')
                ->limit(10)
                ->get(['id', 'username', 'email', 'created_at']); // Changed 'name' to 'username'

            // Get recent transactions with user relationship
            $recentTransactions = Transaction::with(['user' => function($query) {
                    $query->select('id', 'username', 'email'); // Changed 'name' to 'username'
                }])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // Get pending KYC requests with user relationship
            $recentKyc = KycVerification::with(['user' => function($query) {
                    $query->select('id', 'username', 'email'); // Changed 'name' to 'username'
                }])
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'recent_users' => $recentUsers,
                    'recent_transactions' => $recentTransactions,
                    'pending_kyc' => $recentKyc
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Recent activity error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recent activity',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}