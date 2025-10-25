<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferralController extends Controller
{
    public function getReferralStats(Request $request)
    {
        $user = $request->user();
        
        // Get all referrals
        $allReferrals = $user->referrals()
            ->select('id', 'username', 'created_at', 'kyc_status', 'is_verified')
            ->latest()
            ->paginate(10);

        // Count verified referrals (completed KYC)
        $verifiedReferralsCount = $user->referrals()
            ->where('kyc_status', 'verified')
            ->where('is_verified', true)
            ->count();

        // Count pending referrals (not completed KYC)
        $pendingReferralsCount = $user->referrals()
            ->where(function($query) {
                $query->where('kyc_status', '!=', 'verified')
                      ->orWhere('is_verified', false);
            })
            ->count();

        // Calculate total earned from referrals
        $totalUSDCEarned = $verifiedReferralsCount * 0.1;
        $totalCMEMEEarned = $verifiedReferralsCount * 0.5;

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_referrals' => $allReferrals->total(),
                'verified_referrals' => $verifiedReferralsCount,
                'pending_referrals' => $pendingReferralsCount,
                'total_usdc_earned' => (float) $totalUSDCEarned,
                'total_cmeme_earned' => (float) $totalCMEMEEarned,
                'referrals' => [
                    'data' => $allReferrals->items(),
                    'current_page' => $allReferrals->currentPage(),
                    'last_page' => $allReferrals->lastPage(),
                    'total' => $allReferrals->total(),
                ]
            ]
        ]);
    }

    /**
     * Update referral rewards when a referred user completes KYC
     */
    public static function updateReferralRewards(User $referredUser)
    {
        if (!$referredUser->referred_by || !$referredUser->isKycVerified()) {
            return;
        }

        $referrer = User::find($referredUser->referred_by);
        if (!$referrer) {
            return;
        }

        try {
            DB::transaction(function () use ($referrer, $referredUser) {
                // Calculate rewards
                $usdcReward = 0.1;
                $cmemeReward = 0.5;

                // Add rewards directly to referrer's main balances
                $referrer->increment('usdc_balance', $usdcReward);
                $referrer->increment('token_balance', $cmemeReward);

                // Create transaction records
                Transaction::createReferralReward(
                    $referrer, 
                    $usdcReward, 
                    $referredUser,
                    'USDC'
                );

                Transaction::createReferralReward(
                    $referrer, 
                    $cmemeReward, 
                    $referredUser,
                    'CMEME'
                );

                // Also update referral tracking balances for statistics
                $referrer->increment('referral_usdc_balance', $usdcReward);
                $referrer->increment('referral_token_balance', $cmemeReward);
            });

        } catch (\Exception $e) {
            Log::error('Failed to update referral rewards: ' . $e->getMessage());
        }
    }
}