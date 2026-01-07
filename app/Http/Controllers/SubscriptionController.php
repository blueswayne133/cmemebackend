<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SubscriptionController extends Controller
{
    /**
     * Subscribe user - One time subscription with CMEME or USDC payment
     */
    public function subscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'currency' => 'required|in:CMEME,USDC',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $currency = $request->currency;

        // Get subscription fees from settings
        $subscriptionFeeCMEME = \App\Models\Setting::getWalletValue('subscription_fee_cmeme', 1500);
        $subscriptionFeeUSDC = \App\Models\Setting::getWalletValue('subscription_fee_usdc', 1500);

        // Determine cost based on currency
        $subscriptionCost = $currency === 'CMEME' ? $subscriptionFeeCMEME : $subscriptionFeeUSDC;

        // Check if user has already subscribed
        if ($user->has_subscribed) {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already subscribed. This is a one-time subscription.'
            ], 400);
        }

        // Check balance based on currency
        if ($currency === 'CMEME') {
            if ($user->token_balance < $subscriptionCost) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Insufficient CMEME balance. You need {$subscriptionCost} CMEME tokens to subscribe."
                ], 400);
            }
        } else {
            if ($user->usdc_balance < $subscriptionCost) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Insufficient USDC balance. You need {$subscriptionCost} USDC to subscribe."
                ], 400);
            }
        }

        try {
            DB::beginTransaction();

            // Deduct payment from user balance
            if ($currency === 'CMEME') {
                $user->decrement('token_balance', $subscriptionCost);
            } else {
                $user->decrement('usdc_balance', $subscriptionCost);
            }
            
            // Mark user as subscribed
            $user->update([
                'has_subscribed' => true,
                'subscribed_at' => now(),
            ]);

            // Create transaction record for payment
            Transaction::create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_WITHDRAWAL,
                'amount' => -$subscriptionCost,
                'description' => "Premium Subscription - {$subscriptionCost} {$currency}",
                'metadata' => [
                    'subscription' => true,
                    'one_time' => true,
                    'amount' => $subscriptionCost,
                    'currency' => $currency,
                    'subscription_type' => 'premium',
                ],
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription successful! You are now a premium subscriber.',
                'data' => [
                    'has_subscribed' => true,
                    'subscribed_at' => $user->subscribed_at,
                    'token_balance' => $user->fresh()->token_balance,
                    'usdc_balance' => $user->fresh()->usdc_balance,
                    'amount_paid' => $subscriptionCost,
                    'currency_paid' => $currency,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process subscription: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get subscription status and fees
     */
    public function getStatus(Request $request)
    {
        $user = $request->user();

        // Get subscription fees from settings
        $subscriptionFeeCMEME = \App\Models\Setting::getWalletValue('subscription_fee_cmeme', 1500);
        $subscriptionFeeUSDC = \App\Models\Setting::getWalletValue('subscription_fee_usdc', 1500);

        return response()->json([
            'status' => 'success',
            'data' => [
                'has_subscribed' => $user->has_subscribed ?? false,
                'subscribed_at' => $user->subscribed_at,
                'can_subscribe' => !($user->has_subscribed ?? false),
                'subscription_fee_cmeme' => $subscriptionFeeCMEME,
                'subscription_fee_usdc' => $subscriptionFeeUSDC,
                'user_balance_cmeme' => $user->token_balance,
                'user_balance_usdc' => $user->usdc_balance,
                'can_pay_cmeme' => ($user->token_balance >= $subscriptionFeeCMEME),
                'can_pay_usdc' => ($user->usdc_balance >= $subscriptionFeeUSDC),
            ]
        ]);
    }
}

