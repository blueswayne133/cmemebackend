<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PlatformController extends Controller
{
    public function getPlatformStats()
    {
        try {
            // Count active users (those who logged in within last 30 days)
            // Handle null last_login_at values properly
            $activeUsers = User::whereNotNull('last_login_at')
                              ->where('last_login_at', '>=', now()->subDays(30))
                              ->count();
            
            $totalMined = User::sum('token_balance') ?? 0;
            $totalUSDC = User::sum('usdc_balance') ?? 0;
            $uptime = 99.9;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'active_miners' => $activeUsers,
                    'total_mined' => round($totalMined, 2),
                    'total_usdc' => round($totalUSDC, 2),
                    'uptime' => $uptime,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('PlatformController getPlatformStats error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch platform statistics: ' . $e->getMessage(),
            ], 500);
        }
    }
}
