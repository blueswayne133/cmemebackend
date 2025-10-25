<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\KycVerification;
use App\Models\Transaction;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function getStats()
    {
        $totalUsers = User::count();
        $activeUsers = User::where('is_active', true)->count();
        $pendingKyc = KycVerification::where('status', 'pending')->count();
        $pendingDeposits = Transaction::where('type', 'deposit')
            ->where('status', 'pending')
            ->count();
        $totalVolume = Transaction::where('status', 'completed')
            ->sum('amount');
        $todayMining = Transaction::where('type', 'mining')
            ->whereDate('created_at', today())
            ->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'totalUsers' => $totalUsers,
                'activeUsers' => $activeUsers,
                'pendingKyc' => $pendingKyc,
                'pendingDeposits' => $pendingDeposits,
                'totalVolume' => $totalVolume,
                'todayMining' => $todayMining,
            ]
        ]);
    }
}