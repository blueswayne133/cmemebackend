<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepositController extends Controller
{
    public function confirmDeposit(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:10',
            'transaction_hash' => 'required|string|max:255',
            'from_wallet' => 'required|string|max:255',
            'currency' => 'required|string|in:USDC',
            'network' => 'required|string|in:base',
        ]);

        try {
            DB::beginTransaction();

            // Check if transaction hash already exists
            $existingDeposit = Deposit::where('transaction_hash', $request->transaction_hash)->first();
            if ($existingDeposit) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This transaction has already been submitted.'
                ], 422);
            }

            $user = $request->user();

            // Create deposit record
            $deposit = Deposit::create([
                'user_id' => $user->id,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'network' => $request->network,
                'transaction_hash' => $request->transaction_hash,
                'from_wallet_address' => $request->from_wallet,
                'to_wallet_address' => $user->wallet_address, // User's deposit address
                'status' => Deposit::STATUS_PENDING,
            ]);

            // Create transaction record
            Transaction::create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_DEPOSIT,
                'amount' => $request->amount,
                'description' => 'USDC Deposit - Pending Approval',
                'metadata' => [
                    'deposit_id' => $deposit->id,
                    'currency' => 'USDC',
                    'network' => 'base',
                    'transaction_hash' => $request->transaction_hash,
                    'status' => 'pending'
                ],
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Deposit confirmed and pending approval.',
                'data' => [
                    'deposit' => $deposit
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to confirm deposit. Please try again.'
            ], 500);
        }
    }

    public function getDepositHistory(Request $request)
    {
        $user = $request->user();
        
        $deposits = Deposit::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'status' => 'success',
            'data' => $deposits
        ]);
    }
}