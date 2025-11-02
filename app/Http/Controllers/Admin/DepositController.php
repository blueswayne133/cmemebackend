<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepositController extends Controller
{
    public function index(Request $request)
    {
        $query = Deposit::with('user')->latest();

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by currency
        if ($request->has('currency') && $request->currency !== 'all') {
            $query->where('currency', $request->currency);
        }

        // Search by user or transaction hash
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('transaction_hash', 'like', "%{$search}%")
                  ->orWhereHas('user', function($userQuery) use ($search) {
                      $userQuery->where('username', 'like', "%{$search}%")
                               ->orWhere('uid', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $deposits = $query->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $deposits
        ]);
    }

    public function approve($id)
    {
        DB::beginTransaction();

        try {
            $deposit = Deposit::with('user')->findOrFail($id);

            if ($deposit->status !== Deposit::STATUS_PENDING) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Deposit is not pending approval.'
                ], 422);
            }

            // Approve deposit
            $deposit->approve('Approved by admin');

            // Update user balance
            $user = $deposit->user;
            if ($deposit->currency === 'USDC') {
                $user->increment('usdc_balance', $deposit->amount);
            } else {
                $user->increment('token_balance', $deposit->amount);
            }

            // Update transaction status
            Transaction::where('metadata->deposit_id', $deposit->id)
                ->update([
                    'description' => 'USDC Deposit - Approved',
                    'metadata->status' => 'approved'
                ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Deposit approved successfully.',
                'data' => $deposit->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve deposit.'
            ], 500);
        }
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        DB::beginTransaction();

        try {
            $deposit = Deposit::findOrFail($id);

            if ($deposit->status !== Deposit::STATUS_PENDING) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Deposit is not pending approval.'
                ], 422);
            }

            // Reject deposit
            $deposit->reject($request->reason);

            // Update transaction status
            Transaction::where('metadata->deposit_id', $deposit->id)
                ->update([
                    'description' => 'USDC Deposit - Rejected',
                    'metadata->status' => 'rejected',
                    'metadata->rejection_reason' => $request->reason
                ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Deposit rejected successfully.',
                'data' => $deposit->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject deposit.'
            ], 500);
        }
    }

    public function show($id)
    {
        $deposit = Deposit::with('user')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $deposit
        ]);
    }

    public function stats()
    {
        $stats = [
            'total' => Deposit::count(),
            'pending' => Deposit::pending()->count(),
            'approved' => Deposit::approved()->count(),
            'rejected' => Deposit::where('status', Deposit::STATUS_REJECTED)->count(),
            'total_amount' => [
                'USDC' => Deposit::approved()->where('currency', 'USDC')->sum('amount'),
                'CMEME' => Deposit::approved()->where('currency', 'CMEME')->sum('amount'),
            ]
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }
}