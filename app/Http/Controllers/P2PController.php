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
use Cloudinary\Cloudinary;
use Cloudinary\Api\Upload\UploadApi;

class P2PController extends Controller
{

    protected $cloudinary;
    protected $uploadApi;

    public function __construct()
    {
        $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                'api_key' => env('CLOUDINARY_API_KEY'),
                'api_secret' => env('CLOUDINARY_API_SECRET'),
            ],
            'url' => [
                'secure' => true
            ]
        ]);
        $this->uploadApi = new UploadApi();
    }
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
        'time_limit' => 'required|integer|min:5|max:60',
        'terms' => 'required|string|max:1000',
        // Only require payment_details for BUY trades
        'payment_details' => 'nullable|string|max:1000',
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

        // Handle SELL logic
        if ($request->type === 'sell') {
            if ($user->token_balance < $request->amount) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient CMEME balance'
                ], 400);
            }

            // Lock tokens
            $user->token_balance -= $request->amount;
            $user->save();
        }

        // Determine payment details
        $paymentDetails = $request->type === 'sell'
            ? 'pending'
            : ($request->payment_details ?? 'N/A');

        $trade = P2PTrade::create([
            'seller_id' => $user->id,
            'type' => $request->type,
            'amount' => $request->amount,
            'price' => $request->price,
            'total' => $total,
            'payment_method' => $request->payment_method,
            'payment_details' => $paymentDetails,
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

            // CORRECTED TOKEN LOCKING LOGIC:
            if ($trade->type === 'sell') {
                // SELL ORDER: Seller wants to SELL CMEME, Buyer wants to BUY CMEME
                // Seller's tokens are already locked when creating the sell order
                // Buyer doesn't need token lock - they pay USD
                
            } else {
                // BUY ORDER: Seller wants to BUY CMEME, Buyer wants to SELL CMEME  
                // Buyer needs CMEME tokens to sell to the seller
                if ($user->token_balance < $trade->amount) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Insufficient CMEME balance to sell. You need ' . $trade->amount . ' CMEME.'
                    ], 400);
                }
                
                // Lock buyer's CMEME tokens for buy orders
                $user->token_balance -= $trade->amount;
                $user->save();
            }

            $timeLimit = $trade->time_limit ?? 30;

            $trade->update([
                'buyer_id' => $user->id,
                'seller_id' => $trade->seller_id,
                'status' => 'processing',
                'expires_at' => now()->addMinutes((int) $timeLimit),
            ]);

            // Create system message for trade initiation
            P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $user->id,
                'message' => "Trade initiated successfully. Please proceed with payment.",
                'type' => 'system',
                'is_system' => true
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
            
            // Upload to Cloudinary
            $uploadResult = $this->uploadApi->upload($file->getRealPath(), [
                'folder' => 'p2p_payment_proofs/' . $trade->id,
                'resource_type' => 'image',
                'quality' => 'auto:best'
            ]);

            $proof = P2PTradeProof::create([
                'trade_id' => $trade->id,
                'uploaded_by' => $user->id,
                'proof_type' => 'payment_proof',
                'file_path' => $uploadResult['secure_url'],
                'file_public_id' => $uploadResult['public_id'],
                'description' => $request->description,
            ]);

            // Create system message for proof upload
            P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $user->id,
                'message' => "Payment proof uploaded successfully.",
                'type' => 'system',
                'is_system' => true
            ]);

            $trade->load('proofs');

            return response()->json([
                'status' => 'success',
                'message' => 'Payment proof uploaded successfully',
                'data' => [
                    'proof' => $proof,
                    'file_url' => $uploadResult['secure_url'],
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
            ->where(function($query) use ($user) {
                $query->where('seller_id', $user->id)
                      ->orWhere('buyer_id', $user->id);
            })
            ->with(['seller', 'buyer', 'proofs'])
            ->findOrFail($tradeId);

        // CORRECTED LOGIC: Check who should be marking payment as sent
        // if ($trade->type === 'sell') {
        //     // SELL ORDER: Buyer pays USD to seller, so BUYER marks payment as sent
        //     if ($trade->buyer_id !== $user->id) {
        //         return response()->json([
        //             'status' => 'error',
        //             'message' => 'Only buyer can mark payment as sent for sell orders'
        //         ], 403);
        //     }
        // } else {
        //     // BUY ORDER: Seller pays USD to buyer, so SELLER marks payment as sent
        //     if ($trade->seller_id !== $user->id) {
        //         return response()->json([
        //             'status' => 'error',
        //             'message' => 'Only seller can mark payment as sent for buy orders'
        //         ], 403);
        //     }
        // }

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
                'message' => "Payment marked as sent. Waiting for counterparty confirmation.",
                'type' => 'system',
                'is_system' => true
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment marked as sent. Counterparty has been notified.',
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
                ->whereNotNull('paid_at')
                ->with(['seller', 'buyer'])
                ->findOrFail($tradeId);

            // CORRECTED LOGIC: Check who should be confirming payment
            // if ($trade->type === 'sell') {
            //     // SELL ORDER: Seller confirms they received USD and releases CMEME to buyer
            //     if ($trade->seller_id !== $user->id) {
            //         return response()->json([
            //             'status' => 'error',
            //             'message' => 'Only seller can confirm payment for sell orders'
            //         ], 403);
            //     }
            // } else {
            //     // BUY ORDER: Buyer confirms they received USD and releases CMEME to seller
            //     if ($trade->buyer_id !== $user->id) {
            //         return response()->json([
            //             'status' => 'error',
            //             'message' => 'Only buyer can confirm payment for buy orders'
            //         ], 403);
            //     }
            // }

            DB::beginTransaction();

            if ($trade->type === 'sell') {
                // SELL ORDER: Transfer CMEME from seller to buyer
                $buyer = User::find($trade->buyer_id);
                $buyer->token_balance += $trade->amount;
                $buyer->save();
                
                // Seller's tokens were already locked when creating the trade
                // No need to deduct from seller again
                
            } else {
                // BUY ORDER: Transfer CMEME from buyer to seller
                $seller = User::find($trade->seller_id);
                $seller->token_balance += $trade->amount;
                $seller->save();
                
                // Buyer's tokens were locked when initiating the trade
                // No need to deduct from buyer again
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
                'message' => "Payment confirmed! Tokens released successfully.",
                'type' => 'system',
                'is_system' => true
            ]);

            // Update user trade statistics
            $this->updateUserTradeStats($trade->seller_id);
            $this->updateUserTradeStats($trade->buyer_id);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment confirmed! Tokens released.',
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
                ->whereNotNull('paid_at')
                ->with(['seller', 'buyer', 'proofs'])
                ->firstOrFail($tradeId);

            // CORRECTED LOGIC: Check who should be rejecting payment
            if ($trade->type === 'sell') {
                // SELL ORDER: Seller rejects payment
                if ($trade->seller_id !== $user->id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Only seller can reject payment for sell orders'
                    ], 403);
                }
            } else {
                // BUY ORDER: Buyer rejects payment
                if ($trade->buyer_id !== $user->id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Only buyer can reject payment for buy orders'
                    ], 403);
                }
            }

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

            // Refund tokens based on trade type when payment is rejected
            if ($trade->type === 'buy') {
                // For buy orders: refund CMEME tokens to buyer
                if ($trade->buyer_id) {
                    User::where('id', $trade->buyer_id)->increment('token_balance', $trade->amount);
                }
            }
            // For sell orders, tokens remain with seller (they were locked initially)

            // Mark trade as disputed when payment is rejected
            $trade->update([
                'status' => 'disputed',
                'cancellation_reason' => "Payment rejected: " . $request->reason,
            ]);

            // Create system message
            P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $user->id,
                'message' => "Payment rejected. Reason: {$request->reason}",
                'type' => 'system',
                'is_system' => true
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment rejected. Counterparty has been notified.',
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

            // Refund logic based on trade status, type, and user role
            if ($trade->status === 'processing') {
                if ($trade->type === 'sell') {
                    // SELL ORDER: Refund CMEME to seller
                    User::where('id', $trade->seller_id)->increment('token_balance', $trade->amount);
                } else {
                    // BUY ORDER: Refund CMEME to buyer
                    if ($trade->buyer_id) {
                        User::where('id', $trade->buyer_id)->increment('token_balance', $trade->amount);
                    }
                }
            } else if ($trade->status === 'active') {
                if ($isSeller) {
                    if ($trade->type === 'sell') {
                        // Refund locked CMEME tokens to seller
                        User::where('id', $trade->seller_id)->increment('token_balance', $trade->amount);
                    }
                    // For buy orders in active status, no tokens are locked yet
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

        $query = P2PTrade::with(['seller', 'buyer', 'proofs', 'messages.user'])
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

            // Refund locked tokens for sell orders
            if ($trade->type === 'sell') {
                $user->token_balance += $trade->amount;
                $user->save();
            }
            // For buy orders, no tokens are locked in active status

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




public function updatePaymentDetails(Request $request, $tradeId)
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
        'details' => 'required|string|max:1000',
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

        // Store payment details as simple string/text
        $trade->update([
            'payment_details' => $request->details,
        ]);

        // Create system message
        P2PTradeMessage::create([
            'trade_id' => $trade->id,
            'user_id' => $user->id,
            'message' => "Payment details updated successfully.",
            'type' => 'system',
            'is_system' => true
        ]);

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => 'Payment details updated successfully',
            'data' => [
                'trade' => $trade->fresh(['seller', 'buyer'])
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to update payment details: ' . $e->getMessage()
        ], 500);
    }
}
}