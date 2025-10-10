<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReferralController extends Controller
{
    public function getReferralStats(Request $request)
    {
        $user = $request->user();
        
        $referrals = User::where('referred_by', $user->id)
            ->select('id', 'username', 'email', 'created_at', 'uid')
            ->latest()
            ->paginate(10);

        return response()->json([
            'status' => 'success',
            'message' => 'Referral stats retrieved successfully',
            'data' => [
                'total_referrals' => $user->referrals()->count(),
                'referral_usdc_balance' => $user->referral_usdc_balance ?? 0,
                'referrals' => $referrals
            ]
        ]);
    }

    public function claimReferralRewards(Request $request)
    {
        $user = $request->user();

        // Simple check - if referral USDC balance is 0, nothing to claim
        if ($user->referral_usdc_balance <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No USDC rewards available to claim.'
            ], 400);
        }

        DB::transaction(function () use ($user) {
            // Add USDC to main balance
            $usdcReward = $user->referral_usdc_balance;
            $user->usdc_balance += $usdcReward;
            $user->referral_usdc_balance = 0;

            $user->save();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Referral rewards claimed successfully!',
            'data' => [
                'user' => $request->user()->fresh()
            ]
        ]);
    }
}