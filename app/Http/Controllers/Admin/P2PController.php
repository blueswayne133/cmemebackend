<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\P2PTrade;
use App\Models\P2PDispute;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class P2PController extends Controller
{
    /**
     * Get P2P trade statistics
     */
    public function getStats()
    {
        try {
            $stats = [
                'total' => P2PTrade::count(),
                'active' => P2PTrade::where('status', 'active')->count(),
                'processing' => P2PTrade::where('status', 'processing')->count(),
                'completed' => P2PTrade::where('status', 'completed')->count(),
                'disputed' => P2PTrade::where('status', 'disputed')->count(),
                'cancelled' => P2PTrade::where('status', 'cancelled')->count(),
            ];

            return response()->json([
                'status' => 'success',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch P2P stats: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all P2P trades with filters
     */
/**
 * Get all P2P trades with filters
 */
public function getTrades(Request $request)
{
    try {
        $query = P2PTrade::with(['seller', 'buyer', 'proofs', 'messages.user', 'dispute.raisedBy']);

        // Apply filters
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        if ($request->has('payment_method') && $request->payment_method !== '') {
            $query->where('payment_method', $request->payment_method);
        }

        $trades = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'trades' => $trades
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to fetch P2P trades: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Get specific trade details
     */
    public function getTradeDetails($tradeId)
    {
        try {
            $trade = P2PTrade::with([
                'seller', 
                'buyer', 
                'proofs', 
                'messages.user', 
                'dispute.raisedBy',
                'dispute.resolvedBy'
            ])->findOrFail($tradeId);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'trade' => $trade
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Trade not found: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Cancel a trade as admin
     */
    public function cancelTrade(Request $request, $tradeId)
    {
        try {
            DB::beginTransaction();

            $trade = P2PTrade::with(['seller', 'buyer'])->findOrFail($tradeId);
            $reason = $request->reason ?: 'Admin cancelled';

            // Refund tokens based on trade status and type
            if ($trade->status === 'processing') {
                if ($trade->type === 'sell') {
                    // Refund CMEME to seller
                    User::where('id', $trade->seller_id)->increment('token_balance', $trade->amount);
                } else {
                    // Refund CMEME to buyer
                    if ($trade->buyer_id) {
                        User::where('id', $trade->buyer_id)->increment('token_balance', $trade->amount);
                    }
                }
            } else if ($trade->status === 'active') {
                if ($trade->type === 'sell') {
                    // Refund locked CMEME tokens to seller
                    User::where('id', $trade->seller_id)->increment('token_balance', $trade->amount);
                }
            }

            $trade->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            // Create system message
            \App\Models\P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $trade->seller_id, // Use seller as system user
                'message' => "Trade cancelled by Admin. Reason: {$reason}",
                'type' => 'system',
                'is_system' => true
            ]);

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

    /**
     * Resolve a dispute
     */
    public function resolveDispute(Request $request, $tradeId)
    {
        try {
            DB::beginTransaction();

            $trade = P2PTrade::with(['dispute'])->findOrFail($tradeId);
            
            if (!$trade->dispute) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No dispute found for this trade'
                ], 404);
            }

            $admin = auth('admin')->user();
            $resolution = $request->resolution ?: 'Dispute resolved by admin';

            // Mark dispute as resolved
            $trade->dispute->markAsResolved($resolution, $admin);

            // Update trade status based on resolution
            // You can modify this logic based on your dispute resolution rules
            $trade->update([
                'status' => 'cancelled', // or 'completed' based on your logic
                'cancellation_reason' => "Dispute resolved: {$resolution}",
            ]);

            // Create system message
            \App\Models\P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $trade->seller_id,
                'message' => "Dispute resolved by Admin. Resolution: {$resolution}",
                'type' => 'system',
                'is_system' => true
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Dispute resolved successfully',
                'data' => [
                    'trade' => $trade->fresh(['dispute'])
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to resolve dispute: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force complete a trade
     */
    public function forceCompleteTrade($tradeId)
    {
        try {
            DB::beginTransaction();

            $trade = P2PTrade::with(['seller', 'buyer'])->findOrFail($tradeId);

            if ($trade->status !== 'processing') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only processing trades can be force completed'
                ], 400);
            }

            // Transfer tokens based on trade type
            if ($trade->type === 'sell') {
                // SELL ORDER: Transfer CMEME from seller to buyer
                $buyer = User::find($trade->buyer_id);
                $buyer->token_balance += $trade->amount;
                $buyer->save();
            } else {
                // BUY ORDER: Transfer CMEME from buyer to seller
                $seller = User::find($trade->seller_id);
                $seller->token_balance += $trade->amount;
                $seller->save();
            }

            $trade->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            // Create system message
            \App\Models\P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => $trade->seller_id,
                'message' => "Trade force completed by Admin. Tokens released.",
                'type' => 'system',
                'is_system' => true
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Trade force completed successfully',
                'data' => [
                    'trade' => $trade->fresh()
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to force complete trade: ' . $e->getMessage()
            ], 500);
        }
    }


      /**
     * Get P2P trade history with filters
     */
    public function getTradeHistory(Request $request)
    {
        try {
            $query = P2PTrade::with(['seller', 'buyer', 'proofs', 'dispute'])
                ->whereIn('status', ['completed', 'cancelled', 'disputed']);

            // Apply filters
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            if ($request->has('type') && $request->type !== 'all') {
                $query->where('type', $request->type);
            }

            if ($request->has('payment_method') && $request->payment_method !== '') {
                $query->where('payment_method', $request->payment_method);
            }

            // Date range filter
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            // Search by user
            if ($request->has('user_search') && $request->user_search) {
                $search = $request->user_search;
                $query->where(function($q) use ($search) {
                    $q->whereHas('seller', function($q) use ($search) {
                        $q->where('username', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%");
                    })->orWhereHas('buyer', function($q) use ($search) {
                        $q->where('username', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%");
                    });
                });
            }

            $trades = $query->orderBy('created_at', 'desc')->paginate(20);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'trades' => $trades,
                    'filters' => $request->all()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch trade history: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get P2P trade analytics
     */
    public function getTradeAnalytics(Request $request)
    {
        try {
            // Daily volume for last 30 days
            $dailyVolume = P2PTrade::where('status', 'completed')
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('DATE(created_at) as date, SUM(total) as volume, COUNT(*) as trades')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            // Trade type distribution
            $typeDistribution = P2PTrade::where('status', 'completed')
                ->selectRaw('type, COUNT(*) as count, SUM(total) as volume')
                ->groupBy('type')
                ->get();

            // Payment method distribution
            $paymentDistribution = P2PTrade::where('status', 'completed')
                ->selectRaw('payment_method, COUNT(*) as count, SUM(total) as volume')
                ->groupBy('payment_method')
                ->get();

            // Top traders
            $topTraders = DB::table('p2p_trades')
                ->join('users', function($join) {
                    $join->on('p2p_trades.seller_id', '=', 'users.id')
                         ->orOn('p2p_trades.buyer_id', '=', 'users.id');
                })
                ->where('p2p_trades.status', 'completed')
                ->selectRaw('users.id, users.username, COUNT(*) as trade_count, SUM(p2p_trades.total) as trade_volume')
                ->groupBy('users.id', 'users.username')
                ->orderBy('trade_volume', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'daily_volume' => $dailyVolume,
                    'type_distribution' => $typeDistribution,
                    'payment_distribution' => $paymentDistribution,
                    'top_traders' => $topTraders,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch trade analytics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export trade history
     */
    public function exportTradeHistory(Request $request)
    {
        try {
            $query = P2PTrade::with(['seller', 'buyer'])
                ->whereIn('status', ['completed', 'cancelled', 'disputed']);

            // Apply same filters as getTradeHistory
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            if ($request->has('type') && $request->type !== 'all') {
                $query->where('type', $request->type);
            }

            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $trades = $query->orderBy('created_at', 'desc')->get();

            $csvData = [];
            $csvData[] = [
                'Trade ID',
                'Type',
                'Status',
                'Amount (CMEME)',
                'Price (USD)',
                'Total (USD)',
                'Seller',
                'Buyer',
                'Payment Method',
                'Created At',
                'Completed At',
                'Cancelled At'
            ];

            foreach ($trades as $trade) {
                $csvData[] = [
                    $trade->id,
                    $trade->type,
                    $trade->status,
                    $trade->amount,
                    $trade->price,
                    $trade->total,
                    $trade->seller->username ?? 'N/A',
                    $trade->buyer->username ?? 'N/A',
                    $trade->payment_method,
                    $trade->created_at,
                    $trade->completed_at,
                    $trade->cancelled_at
                ];
            }

            $filename = 'p2p_trade_history_' . date('Y-m-d_H-i-s') . '.csv';
            
            return response()->streamDownload(function() use ($csvData) {
                $file = fopen('php://output', 'w');
                foreach ($csvData as $row) {
                    fputcsv($file, $row);
                }
                fclose($file);
            }, $filename);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export trade history: ' . $e->getMessage()
            ], 500);
        }
    }
}