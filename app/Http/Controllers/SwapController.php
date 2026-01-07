<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SwapController extends Controller
{
    /**
     * Swap CMEME to USDC
     */
    public function swapToUSDC(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.00000001',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $cmemeAmount = $request->amount;
        
        // Get CMEME to USDC rate (default 0.2 if not set)
        $cmemeRate = $user->cmeme_rate ?? 0.2;
        $usdcAmount = $cmemeAmount * $cmemeRate;

        // Check if user has sufficient CMEME balance
        if ($user->token_balance < $cmemeAmount) {
            return response()->json([
                'status' => 'error',
                'message' => 'Insufficient CMEME balance'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Deduct CMEME tokens
            $user->decrement('token_balance', $cmemeAmount);
            
            // Add USDC
            $user->increment('usdc_balance', $usdcAmount);

            // Create transaction record for CMEME deduction
            Transaction::create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_TRANSFER,
                'amount' => -$cmemeAmount,
                'description' => "Swapped {$cmemeAmount} CMEME to {$usdcAmount} USDC",
                'metadata' => [
                    'swap_type' => 'cmeme_to_usdc',
                    'cmeme_amount' => $cmemeAmount,
                    'usdc_amount' => $usdcAmount,
                    'rate' => $cmemeRate,
                    'currency' => 'CMEME',
                ],
            ]);

            // Create transaction record for USDC addition
            Transaction::create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_DEPOSIT,
                'amount' => $usdcAmount,
                'description' => "Received {$usdcAmount} USDC from swap",
                'metadata' => [
                    'swap_type' => 'cmeme_to_usdc',
                    'cmeme_amount' => $cmemeAmount,
                    'usdc_amount' => $usdcAmount,
                    'rate' => $cmemeRate,
                    'currency' => 'USDC',
                ],
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Swap completed successfully',
                'data' => [
                    'cmeme_amount' => $cmemeAmount,
                    'usdc_amount' => $usdcAmount,
                    'rate' => $cmemeRate,
                    'token_balance' => $user->fresh()->token_balance,
                    'usdc_balance' => $user->fresh()->usdc_balance,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process swap: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Swap USDC to CMEME
     */
    public function swapToCMEME(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $usdcAmount = $request->amount;
        
        // Get CMEME to USDC rate (default 0.2 if not set)
        $cmemeRate = $user->cmeme_rate ?? 0.2;
        $cmemeAmount = $usdcAmount / $cmemeRate;

        // Check if user has sufficient USDC balance
        if ($user->usdc_balance < $usdcAmount) {
            return response()->json([
                'status' => 'error',
                'message' => 'Insufficient USDC balance'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Deduct USDC
            $user->decrement('usdc_balance', $usdcAmount);
            
            // Add CMEME tokens
            $user->increment('token_balance', $cmemeAmount);

            // Create transaction record for USDC deduction
            Transaction::create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_TRANSFER,
                'amount' => -$usdcAmount,
                'description' => "Swapped {$usdcAmount} USDC to {$cmemeAmount} CMEME",
                'metadata' => [
                    'swap_type' => 'usdc_to_cmeme',
                    'usdc_amount' => $usdcAmount,
                    'cmeme_amount' => $cmemeAmount,
                    'rate' => $cmemeRate,
                    'currency' => 'USDC',
                ],
            ]);

            // Create transaction record for CMEME addition
            Transaction::create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_DEPOSIT,
                'amount' => $cmemeAmount,
                'description' => "Received {$cmemeAmount} CMEME from swap",
                'metadata' => [
                    'swap_type' => 'usdc_to_cmeme',
                    'usdc_amount' => $usdcAmount,
                    'cmeme_amount' => $cmemeAmount,
                    'rate' => $cmemeRate,
                    'currency' => 'CMEME',
                ],
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Swap completed successfully',
                'data' => [
                    'usdc_amount' => $usdcAmount,
                    'cmeme_amount' => $cmemeAmount,
                    'rate' => $cmemeRate,
                    'token_balance' => $user->fresh()->token_balance,
                    'usdc_balance' => $user->fresh()->usdc_balance,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process swap: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get swap rate and preview
     */
    public function getSwapPreview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'required|in:CMEME,USDC',
            'amount' => 'required|numeric|min:0.00000001',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $from = $request->from;
        $amount = $request->amount;
        $cmemeRate = $user->cmeme_rate ?? 0.2;

        if ($from === 'CMEME') {
            $toAmount = $amount * $cmemeRate;
            $hasBalance = $user->token_balance >= $amount;
        } else {
            $toAmount = $amount / $cmemeRate;
            $hasBalance = $user->usdc_balance >= $amount;
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'from' => $from,
                'from_amount' => $amount,
                'to' => $from === 'CMEME' ? 'USDC' : 'CMEME',
                'to_amount' => $toAmount,
                'rate' => $cmemeRate,
                'has_sufficient_balance' => $hasBalance,
            ]
        ]);
    }
}

