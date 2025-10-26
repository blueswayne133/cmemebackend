<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WalletDetail;
use App\Models\User;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        $query = WalletDetail::with(['user'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status') && $request->status !== 'all') {
            if ($request->status === 'connected') {
                $query->where('is_connected', true);
            } elseif ($request->status === 'disconnected') {
                $query->where('is_connected', false);
            } elseif ($request->status === 'bonus_available') {
                $query->where('is_connected', true)->where('bonus_claimed', false);
            }
        }

        if ($request->has('network') && $request->network !== 'all') {
            $query->where('network', $request->network);
        }

        $wallets = $query->paginate(20);

        $stats = [
            'total' => WalletDetail::count(),
            'connected' => WalletDetail::where('is_connected', true)->count(),
            'bonus_claimed' => WalletDetail::where('bonus_claimed', true)->count(),
            'bonus_available' => WalletDetail::where('is_connected', true)->where('bonus_claimed', false)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'wallets' => $wallets,
                'stats' => $stats
            ]
        ]);
    }

    public function updateWalletStatus(Request $request, $id)
    {
        $request->validate([
            'is_connected' => 'required|boolean',
            'bonus_claimed' => 'required|boolean',
        ]);

        $wallet = WalletDetail::findOrFail($id);
        $wallet->update([
            'is_connected' => $request->is_connected,
            'bonus_claimed' => $request->bonus_claimed,
            'last_updated_at' => now(),
        ]);

        $status = $request->is_connected ? 'connected' : 'disconnected';
        $bonusStatus = $request->bonus_claimed ? 'claimed' : 'unclaimed';

        return response()->json([
            'success' => true,
            'message' => "Wallet marked as {$status} and bonus {$bonusStatus}"
        ]);
    }

    public function grantBonus($id)
    {
        $wallet = WalletDetail::with(['user'])->findOrFail($id);

        if (!$wallet->is_connected) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet is not connected'
            ], 400);
        }

        if ($wallet->bonus_claimed) {
            return response()->json([
                'success' => false,
                'message' => 'Bonus already claimed for this wallet'
            ], 400);
        }

        $wallet->markBonusAsClaimed();

        // Grant bonus to user
        $bonusAmount = config('app.wallet_bonus_amount', 50);
        $user = $wallet->user;
        $user->token_balance += $bonusAmount;
        $user->save();

        // Create transaction record
        \App\Models\Transaction::create([
            'user_id' => $user->id,
            'type' => 'earning',
            'amount' => $bonusAmount,
            'description' => 'Wallet connection bonus',
            'metadata' => [
                'bonus_type' => 'wallet_connection',
                'wallet_id' => $wallet->id,
                'wallet_address' => $wallet->getFormattedAddress(),
            ]
        ]);

        return response()->json([
            'success' => true,
            'message' => "Bonus of {$bonusAmount} tokens granted to user"
        ]);
    }
}