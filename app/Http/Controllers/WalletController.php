<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletDetail;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\TaskController;

class WalletController extends Controller
{
    public function connectWallet(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'wallet_address' => 'required|string|max:42',
            'network' => 'required|string|in:base',
        ]);

        try {
            DB::transaction(function () use ($user, $request) {
                // Update user's wallet address
                $user->update([
                    'wallet_address' => $request->wallet_address
                ]);

                // Create or update wallet detail
                WalletDetail::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'wallet_address' => $request->wallet_address,
                        'network' => $request->network,
                        'connected_at' => now(),
                        'is_connected' => true,
                        'bonus_claimed' => false,
                    ]
                );

                // Auto-complete wallet task
                $taskController = new TaskController();
                $taskController->autoCompleteWalletTask($user);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet connected successfully!',
                'data' => [
                    'wallet_address' => $request->wallet_address,
                    'network' => $request->network
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to connect wallet: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateWallet(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'wallet_address' => 'required|string|max:42',
            'network' => 'required|string|in:base',
        ]);

        try {
            DB::transaction(function () use ($user, $request) {
                // Update user's wallet address
                $user->update([
                    'wallet_address' => $request->wallet_address
                ]);

                // Update wallet detail
                WalletDetail::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'wallet_address' => $request->wallet_address,
                        'network' => $request->network,
                        'connected_at' => now(),
                        'is_connected' => true,
                    ]
                );
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet updated successfully!',
                'data' => [
                    'wallet_address' => $request->wallet_address,
                    'network' => $request->network
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update wallet: ' . $e->getMessage()
            ], 500);
        }
    }

    public function claimWalletBonus(Request $request)
    {
        $user = $request->user();

        try {
            DB::transaction(function () use ($user) {
                $walletDetail = WalletDetail::where('user_id', $user->id)->first();

                if (!$walletDetail) {
                    throw new \Exception('No wallet connected.');
                }

                if ($walletDetail->bonus_claimed) {
                    throw new \Exception('Wallet bonus already claimed.');
                }

                // Award bonus
                $bonusAmount = 0.5;
                $user->increment('token_balance', $bonusAmount);

                // Mark bonus as claimed
                $walletDetail->update([
                    'bonus_claimed' => true,
                    'bonus_claimed_at' => now()
                ]);

                // Record transaction
                Transaction::create([
                    'user_id' => $user->id,
                    'type' => Transaction::TYPE_EARNING,
                    'amount' => $bonusAmount,
                    'description' => 'Wallet connection bonus',
                    'metadata' => [
                        'bonus_type' => 'wallet_connection',
                        'wallet_address' => $walletDetail->wallet_address
                    ],
                ]);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet bonus claimed successfully!',
                'data' => [
                    'bonus_amount' => 0.5,
                    'new_balance' => $user->fresh()->token_balance
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getWalletStatus(Request $request)
    {
        $user = $request->user();
        $walletDetail = WalletDetail::where('user_id', $user->id)->first();

        $status = [
            'wallet_connected' => !empty($user->wallet_address),
            'has_existing_wallet' => !empty($user->wallet_address),
            'wallet_address' => $user->wallet_address,
            'wallet_bonus_claimed' => $walletDetail ? $walletDetail->bonus_claimed : false,
            'wallet_connected_at' => $walletDetail ? $walletDetail->connected_at : null,
        ];

        return response()->json([
            'status' => 'success',
            'data' => $status
        ]);
    }

    public function disconnectWallet(Request $request)
    {
        $user = $request->user();

        try {
            DB::transaction(function () use ($user) {
                // Remove wallet address from user
                $user->update([
                    'wallet_address' => null
                ]);

                // Update wallet detail
                WalletDetail::where('user_id', $user->id)->update([
                    'is_connected' => false,
                    'disconnected_at' => now()
                ]);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet disconnected successfully!'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to disconnect wallet: ' . $e->getMessage()
            ], 500);
        }
    }
}