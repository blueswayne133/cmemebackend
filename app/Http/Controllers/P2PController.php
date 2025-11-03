<?php

namespace App\Http\Controllers;

use App\Models\P2PTrade;
use App\Models\P2PTradeProof;
use App\Models\P2PTradeMessage;
use App\Models\P2PDispute;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class P2PController extends Controller
{
    public function getTrades(Request $request)
    {
        $type = $request->get('type', 'sell');
        $paymentMethod = $request->get('payment_method');
        $amount = $request->get('amount');

        $query = P2PTrade::with(['seller'])
            ->where('status', 'active')
            ->where('type', $type);

        if ($paymentMethod) {
            $query->where('payment_method', $paymentMethod);
        }

        if ($amount) {
            $query->where('amount', '>=', $amount);
        }

        $trades = $query->orderBy('price', $type === 'sell' ? 'asc' : 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => [
                'trades' => $trades,
                'filters' => [
                    'type' => $type,
                    'payment_method' => $paymentMethod,
                    'amount' => $amount,
                ]
            ]
        ]);
    }

    public function createTrade(Request $request)
    {
        $user = $request->user();

        if (!$user->isKycVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification is required to create P2P trades'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:buy,sell',
            'amount' => 'required|numeric|min:1',
            'price' => 'required|numeric|min:0.0001',
            'payment_method' => 'required|string|max:50',
            'terms' => 'required|string|max:1000',
            'time_limit' => 'required|integer|min:5|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $total = $request->amount * $request->price;

            // For sell orders, check token balance
            if ($request->type === 'sell') {
                if ($user->token_balance < $request->amount) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Insufficient CMEME balance'
                    ], 400);
                }

                // Lock tokens by reducing balance
                $user->token_balance -= $request->amount;
                $user->save();
            }

            // For buy orders, check USDC balance
            if ($request->type === 'buy') {
                if ($user->usdc_balance < $total) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Insufficient USDC balance'
                    ], 400);
                }

                // Lock USDC
                $user->usdc_balance -= $total;
                $user->save();
            }

            $trade = P2PTrade::create([
                'seller_id' => $user->id,
                'type' => $request->type,
                'amount' => $request->amount,
                'price' => $request->price,
                'total' => $total,
                'payment_method' => $request->payment_method,
                'terms' => $request->terms,
                'status' => 'active',
                'time_limit' => $request->time_limit,
                'expires_at' => now()->addHours(24),
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'P2P trade created successfully',
                'data' => [
                    'trade' => $trade->load('seller')
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create trade: ' . $e->getMessage()
            ], 500);
        }
    }

   public function initiateTrade(Request $request, $tradeId)
{
    $user = $request->user();

    if (!$user->isKycVerified()) {
        return response()->json([
            'status' => 'error',
            'message' => 'KYC verification is required to initiate P2P trades'
        ], 403);
    }

    $trade = P2PTrade::where('status', 'active')
        ->with(['seller'])
        ->findOrFail($tradeId);

    if ($trade->seller_id === $user->id) {
        return response()->json([
            'status' => 'error',
            'message' => 'You cannot initiate trade with yourself'
        ], 400);
    }

    try {
        DB::beginTransaction();

        if ($trade->type === 'sell') {
            if ($user->usdc_balance < $trade->total) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient USDC balance'
                ], 400);
            }

            $user->usdc_balance -= $trade->total;
            $user->save();
        }

        if ($trade->type === 'buy') {
            if ($user->token_balance < $trade->amount) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient CMEME balance'
                ], 400);
            }

            $user->token_balance -= $trade->amount;
            $user->save();
        }

        // ✅ Ensure time_limit is valid
        $timeLimit = $trade->time_limit ?? 30;

        $trade->update([
            'buyer_id' => $user->id,
            'status' => 'processing',
            'expires_at' => now()->addMinutes((int) $timeLimit),
        ]);

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => 'Trade initiated successfully',
            'data' => [
                'trade' => $trade->load(['seller', 'buyer'])
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to initiate trade: ' . $e->getMessage()
        ], 500);
    }
}


    public function uploadPaymentProof(Request $request, $tradeId)
{
    $user = $request->user();

    if (!$user->isKycVerified()) {
        return response()->json([
            'status' => 'error',
            'message' => 'KYC verification is required for P2P trading'
        ], 403);
    }
    
    $trade = P2PTrade::where('status', 'processing')
        ->where(function($query) use ($user) {
            $query->where('seller_id', $user->id)
                  ->orWhere('buyer_id', $user->id);
        })
        ->findOrFail($tradeId);

    $validator = Validator::make($request->all(), [
        'proof_file' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
        'description' => 'nullable|string|max:500',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $file = $request->file('proof_file');
        $filename = 'p2p/proofs/' . $trade->id . '/' . uniqid() . '.' . $file->getClientOriginalExtension();
        
        // Store in public disk - this is the key change
        $path = $file->storeAs('p2p/proofs/' . $trade->id, $filename, 'public');

        $proof = P2PTradeProof::create([
            'trade_id' => $trade->id,
            'uploaded_by' => $user->id,
            'proof_type' => 'payment_proof',
            'file_path' => $filename,
            'description' => $request->description,
        ]);

        $trade->load('proofs');

        return response()->json([
            'status' => 'success',
            'message' => 'Payment proof uploaded successfully',
            'data' => [
                'proof' => $proof,
                'file_url' => Storage::url($filename), // This will generate the correct URL
                'trade' => $trade->fresh(['seller', 'buyer', 'proofs'])
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to upload proof: ' . $e->getMessage()
        ], 500);
    }
}

    // public function uploadPaymentProof(Request $request, $tradeId)
    // {
    //     $user = $request->user();

    //     if (!$user->isKycVerified()) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'KYC verification is required for P2P trading'
    //         ], 403);
    //     }
        
    //     $trade = P2PTrade::where('status', 'processing')
    //         ->where(function($query) use ($user) {
    //             $query->where('seller_id', $user->id)
    //                   ->orWhere('buyer_id', $user->id);
    //         })
    //         ->findOrFail($tradeId);

    //     $validator = Validator::make($request->all(), [
    //         'proof_file' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
    //         'description' => 'nullable|string|max:500',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Validation failed',
    //             'errors' => $validator->errors()
    //         ], 422);
    //     }

    //     try {
    //         $file = $request->file('proof_file');
    //         $filename = 'p2p/proofs/' . $trade->id . '/' . uniqid() . '.' . $file->getClientOriginalExtension();
            
    //         $path = $file->storeAs('public', $filename);

    //         $proof = P2PTradeProof::create([
    //             'trade_id' => $trade->id,
    //             'uploaded_by' => $user->id,
    //             'proof_type' => 'payment_proof',
    //             'file_path' => $filename,
    //             'description' => $request->description,
    //         ]);

    //         $trade->load('proofs');

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Payment proof uploaded successfully',
    //             'data' => [
    //                 'proof' => $proof,
    //                 'file_url' => Storage::url($filename),
    //                 'trade' => $trade->fresh(['seller', 'buyer', 'proofs'])
    //             ]
    //         ]);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Failed to upload proof: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function markPaymentAsSent(Request $request, $tradeId)
    {
        $user = $request->user();

        if (!$user->isKycVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification is required for P2P trading'
            ], 403);
        }
        
        $trade = P2PTrade::where('status', 'processing')
            ->where('buyer_id', $user->id)
            ->with(['seller', 'buyer', 'proofs'])
            ->findOrFail($tradeId);

        if ($trade->proofs->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please upload payment proof before marking payment as sent'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $trade->update([
                'paid_at' => now(),
            ]);

            // Create system message
            P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $user->id,
                'message' => "Buyer marked payment as sent. Waiting for seller confirmation.",
                'type' => 'system',
                'is_system' => true
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment marked as sent. Seller has been notified.',
                'data' => [
                    'trade' => $trade->fresh(['seller', 'buyer', 'proofs'])
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark payment as sent: ' . $e->getMessage()
            ], 500);
        }
    }

    public function confirmPayment(Request $request, $tradeId)
    {
        try {
            $user = $request->user();

            $trade = P2PTrade::where('status', 'processing')
                ->where('seller_id', $user->id)
                ->whereNotNull('paid_at')
                ->with(['seller', 'buyer'])
                ->firstOrFail();

            DB::beginTransaction();

            // Transfer tokens to buyer
            $buyer = User::find($trade->buyer_id);
            $buyer->token_balance += $trade->amount;
            $buyer->save();

            // Transfer USDC to seller for sell orders
            if ($trade->type === 'sell') {
                $seller = User::find($trade->seller_id);
                $seller->usdc_balance += $trade->total;
                $seller->save();
            }

            // Update trade status
            $trade->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            // Create system message
            P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $user->id,
                'message' => "Seller confirmed payment. Tokens released to buyer.",
                'type' => 'system',
                'is_system' => true
            ]);

            // Update user trade statistics
            $this->updateUserTradeStats($trade->seller_id);
            $this->updateUserTradeStats($trade->buyer_id);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment confirmed! Tokens released to buyer.',
                'data' => [
                    'trade' => $trade->fresh()
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to confirm payment: ' . $e->getMessage()
            ], 500);
        }
    }

    public function rejectPayment(Request $request, $tradeId)
    {
        try {
            $user = $request->user();

            $trade = P2PTrade::where('status', 'processing')
                ->where('seller_id', $user->id)
                ->whereNotNull('paid_at')
                ->with(['seller', 'buyer', 'proofs'])
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Mark trade as disputed when payment is rejected
            $trade->update([
                'status' => 'disputed',
                'cancellation_reason' => "Payment rejected by seller: " . $request->reason,
            ]);

            // Create system message
            P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $user->id,
                'message' => "Seller rejected the payment. Reason: {$request->reason}",
                'type' => 'system',
                'is_system' => true
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment rejected. Buyer has been notified and can file a dispute.',
                'data' => [
                    'trade' => $trade->fresh()
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject payment: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cancelTrade(Request $request, $tradeId)
    {
        $user = $request->user();

        if (!$user->isKycVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification is required for P2P trading'
            ], 403);
        }
        
        $trade = P2PTrade::where(function($query) use ($user) {
                $query->where('seller_id', $user->id)
                      ->orWhere('buyer_id', $user->id);
            })
            ->whereIn('status', ['active', 'processing'])
            ->with(['seller', 'buyer'])
            ->findOrFail($tradeId);

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $isSeller = $trade->seller_id === $user->id;

            // Refund logic based on trade status and user role
            if ($trade->status === 'processing') {
                if ($trade->type === 'sell') {
                    // Refund tokens to seller, USDC to buyer
                    User::where('id', $trade->seller_id)->increment('token_balance', $trade->amount);
                    if ($trade->buyer_id) {
                        User::where('id', $trade->buyer_id)->increment('usdc_balance', $trade->total);
                    }
                } else {
                    // Refund USDC to seller, tokens to buyer
                    User::where('id', $trade->seller_id)->increment('usdc_balance', $trade->total);
                    if ($trade->buyer_id) {
                        User::where('id', $trade->buyer_id)->increment('token_balance', $trade->amount);
                    }
                }
            } else if ($trade->status === 'active') {
                if ($isSeller) {
                    if ($trade->type === 'sell') {
                        // Refund locked tokens to seller
                        User::where('id', $trade->seller_id)->increment('token_balance', $trade->amount);
                    } else {
                        // Refund locked USDC to seller
                        User::where('id', $trade->seller_id)->increment('usdc_balance', $trade->total);
                    }
                }
            }

            $trade->update([
                'status' => 'cancelled',
                'cancellation_reason' => $request->reason,
                'cancelled_at' => now(),
            ]);

            // Create system message
            P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $user->id,
                'message' => "Trade cancelled by {$user->username}. Reason: {$request->reason}",
                'type' => 'system',
                'is_system' => true
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Trade cancelled successfully. Funds have been refunded.',
                'data' => [
                    'trade' => $trade->fresh(['seller', 'buyer'])
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to cancel trade: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getUserTrades(Request $request)
    {
        $user = $request->user();
        $status = $request->get('status', 'all');

        $query = P2PTrade::with(['seller', 'buyer', 'proofs'])
            ->where(function($query) use ($user) {
                $query->where('seller_id', $user->id)
                      ->orWhere('buyer_id', $user->id);
            })
            ->orderBy('created_at', 'desc');

        if ($status !== 'all' && $status !== '') {
            $query->where('status', $status);
        }

        $trades = $query->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => [
                'trades' => $trades
            ]
        ]);
    }

    public function deleteTrade(Request $request, $tradeId)
    {
        $user = $request->user();

        if (!$user->isKycVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification is required for P2P trading'
            ], 403);
        }

        $trade = P2PTrade::where('seller_id', $user->id)
            ->where('status', 'active')
            ->findOrFail($tradeId);

        try {
            DB::beginTransaction();

            // Refund locked tokens/USDC
            if ($trade->type === 'sell') {
                $user->token_balance += $trade->amount;
            } else {
                $user->usdc_balance += $trade->total;
            }
            $user->save();

            $trade->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Trade deleted successfully. Funds have been refunded.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete trade: ' . $e->getMessage()
            ], 500);
        }
    }

    public function sendMessage(Request $request, $tradeId)
    {
        $user = $request->user();

        $trade = P2PTrade::where(function($query) use ($user) {
                $query->where('seller_id', $user->id)
                      ->orWhere('buyer_id', $user->id);
            })
            ->findOrFail($tradeId);

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $message = P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $user->id,
                'message' => $request->message,
                'type' => 'user',
                'is_system' => false
            ]);

            $message->load('user');

            return response()->json([
                'status' => 'success',
                'message' => 'Message sent successfully',
                'data' => [
                    'message' => $message
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send message: ' . $e->getMessage()
            ], 500);
        }
    }

    public function createDispute(Request $request, $tradeId)
    {
        $user = $request->user();

        if (!$user->isKycVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification is required for P2P trading'
            ], 403);
        }
        
        $trade = P2PTrade::where('status', 'processing')
            ->where(function($query) use ($user) {
                $query->where('seller_id', $user->id)
                      ->orWhere('buyer_id', $user->id);
            })
            ->findOrFail($tradeId);

        if ($trade->hasDispute()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dispute already exists for this trade'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
            'evidence' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $dispute = P2PDispute::create([
                'trade_id' => $trade->id,
                'raised_by' => $user->id,
                'reason' => $request->reason,
                'evidence' => $request->evidence,
                'status' => 'open',
            ]);

            // Update trade status
            $trade->update(['status' => 'disputed']);

            // Create system message
            P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $user->id,
                'message' => "Dispute raised by {$user->username}. Reason: {$request->reason}",
                'type' => 'system',
                'is_system' => true
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Dispute created successfully',
                'data' => [
                    'dispute' => $dispute
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create dispute: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getTradeDetails($tradeId)
    {
        $trade = P2PTrade::with(['seller', 'buyer', 'proofs', 'dispute', 'messages.user'])
            ->findOrFail($tradeId);

        return response()->json([
            'status' => 'success',
            'data' => [
                'trade' => $trade
            ]
        ]);
    }

    private function updateUserTradeStats($userId)
    {
        $user = User::find($userId);
        if ($user) {
            $completedTrades = P2PTrade::where(function($query) use ($userId) {
                $query->where('seller_id', $userId)
                      ->orWhere('buyer_id', $userId);
            })
            ->where('status', 'completed')
            ->count();

            // $user->p2p_completed_trades = $completedTrades;
            // $user->p2p_success_rate = $completedTrades > 0 ? 100 : 0;
            $user->save();
        }
    }
}