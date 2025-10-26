<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class PlatformController extends Controller
{
    public function getPlatformStats()
    {
        try {
            $activeUsers = User::where('last_login_at', '>=', now()->subDays(30))->count();
            $totalMined = User::sum('token_balance');
            $totalUSDC = User::sum('usdc_balance');
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
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch platform statistics',
            ], 500);
        }
    }
}
