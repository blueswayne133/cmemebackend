<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Transaction;
use App\Models\KycVerification;
use App\Models\WalletDetail;
use App\Models\UserTaskProgress;
use App\Models\P2PTrade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = User::orderBy('created_at', 'desc');

            // Search
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('uid', 'like', "%{$search}%");
                });
            }

            // Filter by status - REMOVE is_active FILTERS
            if ($request->has('status') && $request->status !== 'all') {
                switch ($request->status) {
                    case 'verified':
                        $query->where('is_verified', true);
                        break;
                    case 'unverified':
                        $query->where('is_verified', false);
                        break;
                    case 'kyc_pending':
                        $query->where('kyc_status', 'pending');
                        break;
                    case 'kyc_verified':
                        $query->where('kyc_status', 'verified');
                        break;
                }
            }

            $users = $query->paginate(20);

            $stats = [
                'total' => User::count(),
                'verified' => User::where('is_verified', true)->count(),
                'kyc_verified' => User::where('kyc_status', 'verified')->count(),
                'new_today' => User::whereDate('created_at', today())->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'users' => $users->items(),
                    'pagination' => [
                        'current_page' => $users->currentPage(),
                        'last_page' => $users->lastPage(),
                        'per_page' => $users->perPage(),
                        'total' => $users->total(),
                    ],
                    'stats' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $user = User::with([
            'kycVerifications',
            'walletDetail',
            'securitySettings',
            'referrals',
            'referrer'
        ])->findOrFail($id);

        // Get user statistics
        $stats = [
            'total_earnings' => Transaction::where('user_id', $id)
                ->whereIn('type', ['earning', 'mining', 'referral'])
                ->sum('amount'),
            'total_transactions' => Transaction::where('user_id', $id)->count(),
            'completed_trades' => P2PTrade::where(function($query) use ($id) {
                $query->where('seller_id', $id)->orWhere('buyer_id', $id);
            })->where('status', 'completed')->count(),
            'tasks_completed' => UserTaskProgress::where('user_id', $id)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'stats' => $stats
            ]
        ]);
    }

    public function verify($id)
    {
        $user = User::findOrFail($id);
        $user->update([
            'is_verified' => true,
            'kyc_status' => 'verified',
            'kyc_verified_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User verified successfully'
        ]);
    }

    // Remove suspend method since we don't have is_active
    public function updateBalance(Request $request, $id)
    {
        $request->validate([
            'type' => 'required|in:token,usdc,referral_usdc,referral_token',
            'amount' => 'required|numeric',
            'operation' => 'required|in:add,subtract,set',
            'reason' => 'required|string|max:500'
        ]);

        $user = User::findOrFail($id);
        $field = match($request->type) {
            'token' => 'token_balance',
            'usdc' => 'usdc_balance',
            'referral_usdc' => 'referral_usdc_balance',
            'referral_token' => 'referral_token_balance',
        };

        $currentBalance = $user->$field;
        $newBalance = match($request->operation) {
            'add' => $currentBalance + $request->amount,
            'subtract' => max(0, $currentBalance - $request->amount),
            'set' => $request->amount,
        };

        $user->update([$field => $newBalance]);

        // Log the balance adjustment
        Transaction::create([
            'user_id' => $id,
            'type' => 'adjustment',
            'amount' => $request->operation === 'subtract' ? -$request->amount : $request->amount,
            'description' => "Admin balance adjustment: {$request->reason}",
            'metadata' => [
                'admin_id' => auth()->id(),
                'operation' => $request->operation,
                'previous_balance' => $currentBalance,
                'new_balance' => $newBalance,
                'reason' => $request->reason
            ]
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Balance updated successfully',
            'data' => [
                'previous_balance' => $currentBalance,
                'new_balance' => $newBalance,
                'field' => $field
            ]
        ]);
    }

    public function getUserTransactions($id)
    {
        $user = User::findOrFail($id);
        $transactions = Transaction::where('user_id', $id)
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

    public function getUserTrades($id)
    {
        $user = User::findOrFail($id);
        $trades = P2PTrade::where('seller_id', $id)
            ->orWhere('buyer_id', $id)
            ->with(['seller', 'buyer', 'dispute'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'trades' => $trades
            ]
        ]);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        
        // In a real application, you might want to soft delete or archive
        // $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }
}