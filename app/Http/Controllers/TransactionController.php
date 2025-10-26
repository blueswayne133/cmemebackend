<?php

namespace App\Http\Controllers;

use App\Mail\TokenReceivedMail;
use App\Models\TokenTransfer;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;


class TransactionController extends Controller
{
    public function getUserTransactions(Request $request)
    {
        $user = $request->user();
        $filter = $request->get('filter', 'all');
        
        $query = Transaction::where('user_id', $user->id)
            ->orderBy('created_at', 'DESC');
            
        if ($filter !== 'all') {
            $query->where('type', $filter);
        }
        
        $transactions = $query->get()->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => (float) $transaction->amount,
                'token' => $transaction->metadata['currency'] ?? 'CMEME',
                'date' => $transaction->created_at->toISOString(),
                'status' => 'completed', // You might want to add status field to your Transaction model
                'description' => $transaction->description,
            ];
        });
        
        return response()->json([
            'status' => 'success',
            'data' => $transactions
        ]);
    }

  /**
     * Send tokens to another user
     */
   /**
 * Send tokens to another user
 */
/**
 * Send tokens to another user
 */
public function sendTokens(Request $request)
{
    $request->validate([
        'amount' => 'required|numeric|min:0.1',
        'recipient_address' => 'required|string',
        'description' => 'nullable|string|max:255',
        'currency' => 'required|string|in:CMEME,USDC'
    ]);

    DB::beginTransaction();

    try {
        $user = $request->user();
        $amount = $request->amount;
        $recipientAddress = $request->recipient_address;
        $description = $request->description;
        $currency = $request->currency;
        $transferFee = 0.5; // Fixed fee for CMEME transfers

        // Check if user has sufficient balance (including fee)
        if ($currency === 'CMEME') {
            $totalDeduction = $amount + $transferFee;
            if ($user->token_balance < $totalDeduction) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Insufficient CMEME token balance. You need {$totalDeduction} CMEME but only have {$user->token_balance} CMEME available."
                ], 422);
            }
        } else {
            if ($user->usdc_balance < $amount) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient USDC balance'
                ], 422);
            }
        }

        // Find recipient by UID or wallet address
        $recipient = User::where('uid', $recipientAddress)
            ->orWhere('wallet_address', $recipientAddress)
            ->first();

        if (!$recipient) {
            return response()->json([
                'status' => 'error',
                'message' => 'Recipient not found. Please check the UID or wallet address.'
            ], 422);
        }

        if ($recipient->id === $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot send tokens to yourself.'
            ], 422);
        }

        // ==========================
        // 💸 PROCESS TRANSFER
        // ==========================
        if ($currency === 'CMEME') {
            $totalDeduction = $amount + $transferFee;

            // Deduct from sender (amount + fee)
            $user->decrement('token_balance', $totalDeduction);

            // Add to recipient (only the amount)
            $recipient->increment('token_balance', $amount);

            // Transaction for sender
            Transaction::create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_TRANSFER,
                'amount' => -$totalDeduction,
                'description' => $description ?: "Transfer to {$recipient->uid}",
                'metadata' => [
                    'direction' => 'sent',
                    'recipient_uid' => $recipient->uid,
                    'recipient_username' => $recipient->username,
                    'transfer_amount' => $amount,
                    'network_fee' => $transferFee,
                    'currency' => 'CMEME',
                    'status' => 'completed'
                ],
            ]);

            // Transaction for recipient
            Transaction::create([
                'user_id' => $recipient->id,
                'type' => Transaction::TYPE_TRANSFER,
                'amount' => $amount,
                'description' => $description ?: "Received from {$user->uid}",
                'metadata' => [
                    'direction' => 'received',
                    'sender_uid' => $user->uid,
                    'sender_username' => $user->username,
                    'transfer_amount' => $amount,
                    'currency' => 'CMEME',
                    'status' => 'completed'
                ],
            ]);
        } else {
            // USDC transfer (no fee)
            $user->decrement('usdc_balance', $amount);
            $recipient->increment('usdc_balance', $amount);

            // Sender transaction
            Transaction::create([
                'user_id' => $user->id,
                'type' => Transaction::TYPE_TRANSFER,
                'amount' => -$amount,
                'description' => $description ?: "USDC Transfer to {$recipient->uid}",
                'metadata' => [
                    'direction' => 'sent',
                    'recipient_uid' => $recipient->uid,
                    'recipient_username' => $recipient->username,
                    'currency' => 'USDC',
                    'status' => 'completed'
                ],
            ]);

            // Recipient transaction
            Transaction::create([
                'user_id' => $recipient->id,
                'type' => Transaction::TYPE_TRANSFER,
                'amount' => $amount,
                'description' => $description ?: "USDC Received from {$user->uid}",
                'metadata' => [
                    'direction' => 'received',
                    'sender_uid' => $user->uid,
                    'sender_username' => $user->username,
                    'currency' => 'USDC',
                    'status' => 'completed'
                ],
            ]);
        }

        DB::commit();

        // Send email notification to recipient using Laravel Mail
        try {
            Mail::to($recipient->email)->send(new TokenReceivedMail($user->username, $currency));
        } catch (\Exception $emailException) {
            Log::error('Failed to send token received email: ' . $emailException->getMessage());
            // Don't fail the transaction if email fails
        }

        return response()->json([
            'status' => 'success',
            'message' => "Successfully sent {$amount} {$currency} to {$recipient->username}",
            'data' => [
                'amount' => $amount,
                'currency' => $currency,
                'fee' => $currency === 'CMEME' ? $transferFee : 0,
                'recipient' => [
                    'uid' => $recipient->uid,
                    'username' => $recipient->username
                ]
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Token transfer error: ' . $e->getMessage());

        return response()->json([
            'status' => 'error',
            'message' => 'Failed to complete transfer. Please try again.'
        ], 500);
    }
}


    /**
     * Get transfer fee information
     */
    public function getTransferFee(Request $request)
    {
        $currency = $request->get('currency', 'CMEME');
        
        $fee = $currency === 'CMEME' ? 0.5 : 0;

        return response()->json([
            'status' => 'success',
            'data' => [
                'currency' => $currency,
                'fee' => $fee,
                'description' => $currency === 'CMEME' ? 'Network fee' : 'No fee for USDC transfers'
            ]
        ]);
    }
}