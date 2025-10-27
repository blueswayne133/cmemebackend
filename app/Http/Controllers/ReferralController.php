<?php
// app/Http/Controllers/ReferralController.php

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
        try {
            $user = $request->user();
            
            // Get referrals with pagination
            $referrals = $user->referrals()
                ->select('id', 'username', 'email', 'kyc_status', 'is_verified', 'created_at')
                ->latest()
                ->paginate(10);

            // Calculate stats
            $totalReferrals = $user->referrals()->count();
            $verifiedReferrals = $user->referrals()->where('kyc_status', 'verified')->count();
            $pendingReferrals = $user->referrals()
            ->where(function($query) {
                $query->where('kyc_status', '!=', 'verified')
                      ->orWhere('is_verified', false);
            })
            ->count();
            
            // Calculate total earnings - use simpler approach without transactions for now
            $totalUsdcEarned = $user->referral_usdc_balance; // Just show pending balance for now
            $totalCmemeEarned = $verifiedReferrals * 0.5;; // We'll calculate this from actual rewards given

            $stats = [
                'total_referrals' => $totalReferrals,
                'verified_referrals' => $verifiedReferrals,
                'pending_referrals' => $pendingReferrals,
                'total_usdc_earned' => $totalUsdcEarned,
                'total_cmeme_earned' => $totalCmemeEarned,
                'pending_usdc_balance' => $user->referral_usdc_balance,
                'referrals' => [
                    'data' => $referrals->items(),
                    'current_page' => $referrals->currentPage(),
                    'last_page' => $referrals->lastPage(),
                    'total' => $referrals->total(),
                ]
            ];

            return response()->json([
                'status' => 'success',
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error in getReferralStats: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch referral stats: ' . $e->getMessage()
            ], 500);
        }
    }

    public function claimUSDC(Request $request)
    {
        $user = $request->user();
        
        // Check if claiming is enabled
        if (!$user->can_claim_referral_usdc) {
            return response()->json([
                'status' => 'error',
                'message' => 'USDC claiming is currently disabled by admin'
            ], 400);
        }
        
        // Check if user has pending USDC to claim
        if ($user->referral_usdc_balance <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No USDC available to claim'
            ], 400);
        }
        
        try {
            DB::beginTransaction();
            
            $claimAmount = $user->referral_usdc_balance;
            
            // Transfer from referral balance to main USDC balance
            $user->usdc_balance += $claimAmount;
            $user->referral_usdc_balance = 0;
            $user->save();
            
            // // Create transaction record - use basic fields that exist in your model
            // Transaction::create([
            //     'user_id' => $user->id,
            //     'type' => 'referral',
            //     'amount' => $claimAmount,
            //     'description' => 'Claimed referral USDC rewards',
            // ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Successfully claimed $' . number_format($claimAmount, 2) . ' USDC',
                'data' => [
                    'claimed_amount' => $claimAmount,
                    'new_usdc_balance' => $user->usdc_balance
                ]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error claiming USDC: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to claim USDC: ' . $e->getMessage()
            ], 500);
        }
    }

    public static function updateReferralRewards(User $referredUser)
    {
        // This method can be used to update overall referral statistics
        // You can add any additional logic needed for referral tracking here
    }
}