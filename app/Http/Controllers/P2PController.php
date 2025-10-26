<?php

namespace App\Http\Controllers;

use App\Models\P2PTrade;
use App\Models\P2PTradeProof;
use App\Models\P2PDispute;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class P2PController extends Controller
{
    // Get all active P2P trades
    public function getTrades(Request $request)
    {
        $type = $request->get('type', 'sell');
        $paymentMethod = $request->get('payment_method');
        $amount = $request->get('amount');

        $query = P2PTrade::with(['seller'])
            ->active()
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

    // Create a new P2P trade
    public function createTrade(Request $request)
    {
        $user = $request->user();

        // Check if user is KYC verified
        if (!$user->isKycVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification is required to create P2P trades'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:buy,sell',
            'amount' => 'required|numeric|min:1|max:100000',
            'price' => 'required|numeric|min:0.0001',
            'payment_method' => 'required|string|max:50',
            'payment_details' => 'sometimes|array',
            'terms' => 'nullable|string|max:1000',
            'time_limit' => 'required|integer|min:5|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // For sell orders, check if user has enough balance and lock tokens
        if ($request->type === 'sell') {
            if ($user->token_balance < $request->amount) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient CMEME balance'
                ], 400);
            }

            // Lock the tokens for P2P trade
            DB::table('users')
                ->where('id', $user->id)
                ->where('token_balance', '>=', $request->amount)
                ->decrement('token_balance', $request->amount);
        }

        // For buy orders, no locking of USDC - buyer pays after trade initiation
        if ($request->type === 'buy') {
            // Just validate they have enough USDC for when they initiate trades
            $totalCost = $request->amount * $request->price;
            if ($user->usdc_balance < $totalCost) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient USDC balance for this trade'
                ], 400);
            }
        }

        try {
            $trade = P2PTrade::create([
                'seller_id' => $user->id,
                'type' => $request->type,
                'amount' => $request->amount,
                'price' => $request->price,
                'total' => $request->amount * $request->price,
                'payment_method' => $request->payment_method,
                'payment_details' => $request->payment_details ?? [],
                'status' => 'active',
                'terms' => $request->terms,
                'time_limit' => $request->time_limit,
            ]);

            // Create transaction record
            Transaction::createP2PTransaction(
                $user,
                $request->type === 'sell' ? -$request->amount : 0,
                "Created P2P {$request->type} order for {$request->amount} CMEME",
                [
                    'trade_id' => $trade->id,
                    'action' => 'create',
                    'price' => $request->price,
                    'total' => $request->amount * $request->price
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'P2P trade created successfully',
                'data' => [
                    'trade' => $trade->load('seller')
                ]
            ]);

        } catch (\Exception $e) {
            // Refund tokens if sell order creation fails
            if ($request->type === 'sell') {
                DB::table('users')
                    ->where('id', $user->id)
                    ->increment('token_balance', $request->amount);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create trade: ' . $e->getMessage()
            ], 500);
        }
    }

    // Initiate a trade (buyer starts the trade)
    public function initiateTrade(Request $request, $tradeId)
    {
        $user = $request->user();

        // Check if user is KYC verified
        if (!$user->isKycVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification is required to initiate P2P trades'
            ], 403);
        }
        
        $trade = P2PTrade::active()->findOrFail($tradeId);

        // Can't trade with yourself
        if ($trade->seller_id === $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot initiate trade with yourself'
            ], 400);
        }

        // For buy orders (user is buying CMEME), lock buyer's USDC
        if ($trade->type === 'sell') {
            if ($user->usdc_balance < $trade->total) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient USDC balance'
                ], 400);
            }

            // Lock USDC for trade
            DB::table('users')
                ->where('id', $user->id)
                ->where('usdc_balance', '>=', $trade->total)
                ->decrement('usdc_balance', $trade->total);
        }

        // For sell orders (user is selling CMEME), tokens are already locked from creation
        // No additional locking needed for buyer when initiating against a buy order

        try {
            DB::beginTransaction();

            $trade->update([
                'buyer_id' => $user->id,
                'status' => 'processing',
                'expires_at' => now()->addMinutes($trade->time_limit),
            ]);

            // Create transaction record
            Transaction::createP2PTransaction(
                $user,
                0,
                "Initiated P2P trade #{$trade->id} for {$trade->amount} CMEME",
                [
                    'trade_id' => $trade->id,
                    'action' => 'initiate',
                    'counterparty' => $trade->seller->username
                ]
            );

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

            // Refund USDC if buy order initiation fails
            if ($trade->type === 'sell') {
                DB::table('users')
                    ->where('id', $user->id)
                    ->increment('usdc_balance', $trade->total);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to initiate trade: ' . $e->getMessage()
            ], 500);
        }
    }

    // Upload payment proof
    public function uploadPaymentProof(Request $request, $tradeId)
    {
        $user = $request->user();

        // Check if user is KYC verified
        if (!$user->isKycVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification is required for P2P trading'
            ], 403);
        }
        
        $trade = P2PTrade::processing()
            ->where(function($query) use ($user) {
                $query->where('seller_id', $user->id)
                      ->orWhere('buyer_id', $user->id);
            })
            ->findOrFail($tradeId);

        $validator = Validator::make($request->all(), [
            'proof_file' => 'required|image|mimes:jpeg,png,jpg|max:5120',
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
            $filename = 'p2p/proofs/' . $trade->id . '/' . Str::random(20) . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('public', $filename);

            $proof = P2PTradeProof::create([
                'trade_id' => $trade->id,
                'uploaded_by' => $user->id,
                'proof_type' => 'payment_proof',
                'file_path' => $filename,
                'description' => $request->description,
            ]);

            // Mark as paid if proof uploaded by buyer
            if ($trade->buyer_id === $user->id) {
                $trade->markAsPaid();
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment proof uploaded successfully',
                'data' => [
                    'proof' => $proof,
                    'trade' => $trade->fresh()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to upload proof: ' . $e->getMessage()
            ], 500);
        }
    }

    // Confirm payment received (seller confirms)
    public function confirmPayment(Request $request, $tradeId)
    {
        $user = $request->user();

        // Check if user is KYC verified
        if (!$user->isKycVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification is required for P2P trading'
            ], 403);
        }
        
        $trade = P2PTrade::processing()
            ->where('seller_id', $user->id)
            ->findOrFail($tradeId);

        try {
            DB::beginTransaction();

            // Transfer tokens/USDC based on trade type
            if ($trade->type === 'sell') {
                // Seller receives USDC, buyer receives tokens
                DB::table('users')
                    ->where('id', $trade->buyer_id)
                    ->increment('token_balance', $trade->amount);
                
                DB::table('users')
                    ->where('id', $trade->seller_id)
                    ->increment('usdc_balance', $trade->total);
            } else {
                // Buyer receives tokens, seller receives USDC
                DB::table('users')
                    ->where('id', $trade->buyer_id)
                    ->increment('token_balance', $trade->amount);
                
                DB::table('users')
                    ->where('id', $trade->seller_id)
                    ->increment('usdc_balance', $trade->total);
            }

            $trade->markAsCompleted();

            // Create transaction records for both parties
            Transaction::createP2PTransaction(
                User::find($trade->seller_id),
                $trade->type === 'sell' ? $trade->total : $trade->amount,
                "Completed P2P trade #{$trade->id}",
                [
                    'trade_id' => $trade->id,
                    'action' => 'complete_seller',
                    'counterparty' => $trade->buyer->username
                ]
            );

            Transaction::createP2PTransaction(
                User::find($trade->buyer_id),
                $trade->type === 'sell' ? $trade->amount : -$trade->total,
                "Completed P2P trade #{$trade->id}",
                [
                    'trade_id' => $trade->id,
                    'action' => 'complete_buyer',
                    'counterparty' => $trade->seller->username
                ]
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment confirmed and trade completed',
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

    // Cancel trade
    public function cancelTrade(Request $request, $tradeId)
    {
        $user = $request->user();

        // Check if user is KYC verified
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

            // Refund locked funds
            if ($trade->status === 'processing') {
                if ($trade->type === 'sell') {
                    // Refund tokens to seller and USDC to buyer
                    DB::table('users')
                        ->where('id', $trade->seller_id)
                        ->increment('token_balance', $trade->amount);
                    
                    if ($trade->buyer_id) {
                        DB::table('users')
                            ->where('id', $trade->buyer_id)
                            ->increment('usdc_balance', $trade->total);
                    }
                } else {
                    // Refund USDC to buyer (seller's tokens were locked at creation)
                    if ($trade->buyer_id) {
                        DB::table('users')
                            ->where('id', $trade->buyer_id)
                            ->increment('usdc_balance', $trade->total);
                    }
                    
                    // Refund tokens to seller for buy orders
                    DB::table('users')
                        ->where('id', $trade->seller_id)
                        ->increment('token_balance', $trade->amount);
                }
            } else if ($trade->status === 'active' && $trade->type === 'sell') {
                // Refund locked tokens for active sell orders
                DB::table('users')
                    ->where('id', $trade->seller_id)
                    ->increment('token_balance', $trade->amount);
            }

            $trade->markAsCancelled($request->reason);

            // Create transaction record
            Transaction::createP2PTransaction(
                $user,
                0,
                "Cancelled P2P trade #{$trade->id}",
                [
                    'trade_id' => $trade->id,
                    'action' => 'cancel',
                    'reason' => $request->reason
                ]
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Trade cancelled successfully',
                'data' => [
                    'trade' => $trade->fresh()
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

    // Create dispute
    public function createDispute(Request $request, $tradeId)
    {
        $user = $request->user();

        // Check if user is KYC verified
        if (!$user->isKycVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification is required for P2P trading'
            ], 403);
        }
        
        $trade = P2PTrade::processing()
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
            $dispute = P2PDispute::create([
                'trade_id' => $trade->id,
                'raised_by' => $user->id,
                'reason' => $request->reason,
                'evidence' => $request->evidence,
                'status' => 'open',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Dispute created successfully',
                'data' => [
                    'dispute' => $dispute
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create dispute: ' . $e->getMessage()
            ], 500);
        }
    }

    // Get user's P2P trades
    public function getUserTrades(Request $request)
    {
        $user = $request->user();
        $status = $request->get('status', 'all');

        $query = P2PTrade::with(['seller', 'buyer'])
            ->where(function($query) use ($user) {
                $query->where('seller_id', $user->id)
                      ->orWhere('buyer_id', $user->id);
            });

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $trades = $query->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => [
                'trades' => $trades
            ]
        ]);
    }

    // Get trade details
    public function getTradeDetails($tradeId)
    {
        $trade = P2PTrade::with(['seller', 'buyer', 'proofs', 'dispute'])
            ->findOrFail($tradeId);

        return response()->json([
            'status' => 'success',
            'data' => [
                'trade' => $trade
            ]
        ]);
    }
}