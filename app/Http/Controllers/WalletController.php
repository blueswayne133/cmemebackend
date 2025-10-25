<?php
// app/Http/Controllers/WalletController.php

namespace App\Http\Controllers;

use App\Models\WalletDetail;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function connectWallet(Request $request)
    {
        $request->validate([
            'wallet_address' => 'required|string|min:42|max:42|regex:/^0x[a-fA-F0-9]{40}$/',
            'network' => 'required|string|in:base',
        ]);

        $user = $request->user();

        // Check if user already has a wallet for this network
        $existingUserWallet = WalletDetail::where('user_id', $user->id)
            ->where('network', $request->network)
            ->first();

        if ($existingUserWallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'You already have a connected wallet. Please use "Update Wallet" to change your address.'
            ], 400);
        }

        // Check if wallet address is already used by another user
        $existingWallet = WalletDetail::where('wallet_address', $request->wallet_address)
            ->where('network', $request->network)
            ->where('user_id', '!=', $user->id)
            ->first();

        if ($existingWallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'This wallet address is already connected to another account.'
            ], 400);
        }

        DB::transaction(function () use ($user, $request) {
            // Create new wallet detail
            WalletDetail::create([
                'user_id' => $user->id,
                'wallet_address' => $request->wallet_address,
                'network' => $request->network,
                'is_connected' => true,
                'bonus_claimed' => false,
                'connected_at' => now(),
                'last_updated_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Wallet connected successfully! You can now claim your 2500 CMEME bonus.',
            'data' => [
                'wallet' => $user->walletDetail()->first(),
            ]
        ]);
    }

    public function updateWallet(Request $request)
    {
        $request->validate([
            'wallet_address' => 'required|string|min:42|max:42|regex:/^0x[a-fA-F0-9]{40}$/',
            'network' => 'required|string|in:base',
        ]);

        $user = $request->user();

        // Get user's existing wallet
        $walletDetail = $user->walletDetail()->where('network', $request->network)->first();
        
        if (!$walletDetail) {
            return response()->json([
                'status' => 'error',
                'message' => 'No wallet found to update. Please connect a wallet first.'
            ], 400);
        }

        // Check if new wallet address is the same as current one
        if ($walletDetail->wallet_address === $request->wallet_address) {
            return response()->json([
                'status' => 'error',
                'message' => 'This is already your current wallet address.'
            ], 400);
        }

        // Check if new wallet address is already used by another user
        $existingWallet = WalletDetail::where('wallet_address', $request->wallet_address)
            ->where('network', $request->network)
            ->where('user_id', '!=', $user->id)
            ->first();

        if ($existingWallet) {
            return response()->json([
                'status' => 'error',
                'message' => 'This wallet address is already connected to another account.'
            ], 400);
        }

        DB::transaction(function () use ($walletDetail, $request) {
            $walletDetail->update([
                'wallet_address' => $request->wallet_address,
                'last_updated_at' => now(),
                // Keep existing bonus_claimed status
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Wallet address updated successfully!',
            'data' => [
                'wallet' => $walletDetail->fresh(),
            ]
        ]);
    }

    public function disconnectWallet(Request $request)
    {
        $user = $request->user();
        $network = $request->input('network', 'base');

        $walletDetail = $user->walletDetail()->where('network', $network)->first();

        if (!$walletDetail) {
            return response()->json([
                'status' => 'error',
                'message' => 'No wallet found to disconnect.'
            ], 400);
        }

        $walletDetail->update([
            'is_connected' => false,
            'last_updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Wallet disconnected successfully!',
            'data' => [
                'wallet' => $walletDetail->fresh(),
            ]
        ]);
    }

    public function getWalletStatus(Request $request)
    {
        $user = $request->user();
        $walletDetail = $user->walletDetail;

        $walletData = $walletDetail ? [
            'wallet_connected' => $walletDetail->is_connected,
            'wallet_address' => $walletDetail->wallet_address,
            'wallet_network' => $walletDetail->network,
            'wallet_connected_at' => $walletDetail->connected_at,
            'wallet_bonus_claimed' => $walletDetail->bonus_claimed,
            'last_updated_at' => $walletDetail->last_updated_at,
            'formatted_address' => $walletDetail->getFormattedAddress(),
            'is_eligible_for_bonus' => $walletDetail->isEligibleForBonus(),
            'has_existing_wallet' => true,
        ] : [
            'wallet_connected' => false,
            'wallet_address' => null,
            'wallet_network' => null,
            'wallet_connected_at' => null,
            'wallet_bonus_claimed' => false,
            'last_updated_at' => null,
            'formatted_address' => null,
            'is_eligible_for_bonus' => false,
            'has_existing_wallet' => false,
        ];

        return response()->json([
            'status' => 'success',
            'data' => $walletData
        ]);
    }

   // In WalletController.php - update the claimWalletBonus method
public function claimWalletBonus(Request $request)
{
    $user = $request->user();

    DB::transaction(function () use ($user) {
        $walletDetail = $user->walletDetail()->where('is_connected', true)->first();

        if (!$walletDetail) {
            throw new \Exception('No connected wallet found. Please connect your wallet first.');
        }

        if ($walletDetail->bonus_claimed) {
            throw new \Exception('Wallet bonus already claimed.');
        }

        // Mark bonus as claimed
        $walletDetail->markBonusAsClaimed();

        // Reward user with 0.5 CMEME (updated from 2500)
        $user->increment('token_balance', 0.5);

        // Create transaction record
        Transaction::create([
            'user_id' => $user->id,
            'type' => Transaction::TYPE_EARNING,
            'amount' => 0.5,
            'description' => 'Wallet connection bonus',
            'metadata' => [
                'reward_type' => 'wallet_bonus',
                'wallet_address' => $walletDetail->wallet_address,
                'network' => $walletDetail->network,
            ],
        ]);
    });

    return response()->json([
        'status' => 'success',
        'message' => 'Wallet bonus claimed successfully! 0.5 CMEME added to your balance.',
        'data' => [
            'user' => $user->fresh(),
            'wallet' => $user->walletDetail()->first()
        ]
    ]);
}

}