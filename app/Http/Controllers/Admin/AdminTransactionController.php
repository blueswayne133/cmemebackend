<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminTransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = Transaction::with('user')
            ->orderBy('created_at', 'desc');

        // Filters
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $transactions = $query->paginate(20);

        $stats = [
            'total_volume' => Transaction::where('amount', '>', 0)->sum('amount'),
            'today_volume' => Transaction::whereDate('created_at', today())->sum('amount'),
            'total_count' => Transaction::count(),
            'today_count' => Transaction::whereDate('created_at', today())->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $transactions,
                'stats' => $stats,
                'types' => Transaction::getTypes()
            ]
        ]);
    }

    public function show($id)
    {
        $transaction = Transaction::with(['user'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $transaction
        ]);
    }

    public function getUserTransactions($userId)
    {
        $user = User::findOrFail($userId);
        $transactions = Transaction::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'transactions' => $transactions
            ]
        ]);
    }

    public function getStats()
    {
        $today = today();
        $weekAgo = now()->subWeek();

        $volumeStats = Transaction::select([
                DB::raw('SUM(amount) as total_volume'),
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw('DATE(created_at) as date')
            ])
            ->where('created_at', '>=', $weekAgo)
            ->where('amount', '>', 0)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $typeStats = Transaction::select([
                'type',
                DB::raw('SUM(amount) as volume'),
                DB::raw('COUNT(*) as count')
            ])
            ->where('created_at', '>=', $weekAgo)
            ->groupBy('type')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'volume_stats' => $volumeStats,
                'type_stats' => $typeStats,
            ]
        ]);
    }
}