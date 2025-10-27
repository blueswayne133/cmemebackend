<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LeaderboardController extends Controller
{
    public function getLeaderboard(Request $request)
    {
        $period = $request->get('period', 'week'); // 'week' or 'month'
        
        $dateRange = $this->getDateRange($period);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];
        $displayDate = $dateRange['display'];

        // Get leaderboard data from mining transactions only
        $leaderboardData = $this->getMiningLeaderboardData($startDate, $endDate, 100);

        $leaderboard = $leaderboardData->map(function($item, $index) {
            return [
                'rank' => $index + 1,
                'username' => $item->username,
                'uid' => $item->uid,
                'total_earned' => (float) $item->total_mining_earned,
                'transaction_count' => $item->mining_count,
                'avatar_url' => $item->avatar_url
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => $period,
                'date_range' => $displayDate,
                'leaderboard' => $leaderboard
            ]
        ]);
    }

    private function getMiningLeaderboardData($startDate, $endDate, $limit = 100)
    {
        return DB::table('transactions')
            ->select([
                'users.id as user_id',
                'users.username',
                'users.uid',
                'users.avatar_url',
                DB::raw('SUM(transactions.amount) as total_mining_earned'),
                DB::raw('COUNT(transactions.id) as mining_count')
            ])
            ->join('users', 'transactions.user_id', '=', 'users.id')
            ->where('transactions.type', 'mining')
            ->where('transactions.amount', '>', 0)
            ->whereBetween('transactions.created_at', [$startDate, $endDate])
            ->groupBy('users.id', 'users.username', 'users.uid', 'users.avatar_url')
            ->orderBy('total_mining_earned', 'DESC')
            ->limit($limit)
            ->get()
            ->map(function($item) {
                $item->username = $this->maskUsername($item->username);
                return $item;
            });
    }

    private function getDateRange($period)
    {
        $now = Carbon::now();
        
        if ($period === 'month') {
            $startDate = $now->copy()->startOfMonth();
            $endDate = $now->copy()->endOfMonth();
            $displayDate = $startDate->format('d/m/Y') . ' - ' . $endDate->format('d/m/Y');
        } else {
            // Default to week
            $startDate = $now->copy()->startOfWeek();
            $endDate = $now->copy()->endOfWeek();
            $displayDate = $startDate->format('d/m/Y') . ' - ' . $endDate->format('d/m/Y');
        }

        return [
            'start' => $startDate,
            'end' => $endDate,
            'display' => $displayDate
        ];
    }

    private function maskUsername($username)
    {
        if (empty($username)) {
            return 'user***';
        }
        
        if (strlen($username) <= 3) {
            return $username . '***';
        }
        
        $visiblePart = substr($username, 0, 3);
        return $visiblePart . '***';
    }

    // Debug endpoint to check mining transactions
    public function debugMiningTransactions(Request $request)
    {
        $user = $request->user();
        
        $miningTransactions = Transaction::where('type', 'mining')
            ->where('amount', '>', 0)
            ->where('user_id', $user->id)
            ->select('id', 'amount', 'description', 'created_at')
            ->orderBy('created_at', 'DESC')
            ->get();

        $weeklyMining = Transaction::where('type', 'mining')
            ->where('amount', '>', 0)
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
            ->sum('amount');

        $monthlyMining = Transaction::where('type', 'mining')
            ->where('amount', '>', 0)
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->sum('amount');

        return response()->json([
            'status' => 'success',
            'data' => [
                'user_id' => $user->id,
                'username' => $user->username,
                'total_mining_transactions' => $miningTransactions->count(),
                'weekly_mining_total' => $weeklyMining,
                'monthly_mining_total' => $monthlyMining,
                'current_week' => Carbon::now()->startOfWeek()->format('Y-m-d') . ' to ' . Carbon::now()->endOfWeek()->format('Y-m-d'),
                'current_month' => Carbon::now()->startOfMonth()->format('Y-m-d') . ' to ' . Carbon::now()->endOfMonth()->format('Y-m-d'),
                'transactions' => $miningTransactions
            ]
        ]);
    }
}