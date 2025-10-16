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

        // Get leaderboard data using Transaction model
        $leaderboardData = Transaction::getLeaderboardData($startDate, $endDate, 100);

        $leaderboard = $leaderboardData->map(function($item, $index) {
            $user = $item->user;
            return [
                'rank' => $index + 1,
                'username' => $this->maskUsername($user->username),
                'uid' => $user->uid,
                'total_earned' => (float) $item->total_earned,
                'transaction_count' => $item->transaction_count,
                'avatar' => $this->generateAnimatedAvatar($user->username)
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

    private function getDateRange($period)
    {
        $now = Carbon::now();
        
        if ($period === 'month') {
            $startDate = $now->copy()->startOfMonth();
            $endDate = $now->copy()->endOfMonth();
            $displayDate = $startDate->format('d/m/Y') . '-' . $endDate->format('d/m/Y');
        } else {
            // Default to week
            $startDate = $now->copy()->startOfWeek();
            $endDate = $now->copy()->endOfWeek();
            $displayDate = $startDate->format('d/m/Y') . '-' . $endDate->format('d/m/Y');
        }

        return [
            'start' => $startDate,
            'end' => $endDate,
            'display' => $displayDate
        ];
    }

    private function maskUsername($username)
    {
        if (strlen($username) <= 3) {
            return $username . '***';
        }
        
        $visiblePart = substr($username, 0, 3);
        return $visiblePart . '***';
    }

    private function generateAnimatedAvatar($username)
    {
        $hash = crc32($username);
        $hue = $hash % 360;
        
        return "https://api.dicebear.com/7.x/avataaars/svg?seed={$username}&backgroundColor=b6e3f4,c0aede,d1d4f9,ffd5dc,ffdfbf&radius=50&size=80";
    }
}