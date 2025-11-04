<?php
// app/Http/Controllers/Admin/AdminP2PController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\P2PTrade;
use App\Models\P2PDispute;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminP2PController extends Controller
{
    public function getTrades(Request $request)
    {
        $query = P2PTrade::with([
            'seller:id,username,email,p2p_success_rate,p2p_completed_trades',
            'buyer:id,username,email,p2p_success_rate,p2p_completed_trades',
            'proofs',
            'messages.user:id,username',
            'dispute.raisedBy:id,username',
            'dispute.resolvedBy:id,username'
        ]);

        // Apply filters
        if ($request->status && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->type && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        if ($request->payment_method) {
            $query->where('payment_method', $request->payment_method);
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

    public function getTrade($id)
    {
        $trade = P2PTrade::with([
            'seller:id,username,email,created_at,p2p_success_rate,p2p_completed_trades',
            'buyer:id,username,email,created_at,p2p_success_rate,p2p_completed_trades',
            'proofs',
            'messages.user:id,username',
            'dispute.raisedBy:id,username',
            'dispute.resolvedBy:id,username'
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'trade' => $trade
            ]
        ]);
    }

    public function getStats()
    {
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
    }

    public function getDisputes(Request $request)
    {
        $query = P2PDispute::with([
            'trade.seller:id,username',
            'trade.buyer:id,username',
            'raisedBy:id,username',
            'resolvedBy:id,username'
        ]);

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $disputes = $query->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => [
                'disputes' => $disputes
            ]
        ]);
    }

    public function resolveDispute(Request $request, $id)
    {
        $request->validate([
            'resolution' => 'required|string|max:1000'
        ]);

        $dispute = P2PDispute::with('trade')->findOrFail($id);

        if ($dispute->status !== 'open') {
            return response()->json([
                'status' => 'error',
                'message' => 'Dispute is already resolved'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $dispute->update([
                'status' => 'resolved',
                'resolution' => $request->resolution,
                'resolved_by' => auth()->id(),
                'resolved_at' => now()
            ]);

            // Create system message
            \App\Models\P2PTradeMessage::create([
                'trade_id' => $dispute->trade_id,
                'user_id' => auth()->id(),
                'message' => "Dispute resolved by admin. Resolution: {$request->resolution}",
                'type' => 'system',
                'is_system' => true
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Dispute resolved successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to resolve dispute: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cancelTrade(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:1000'
        ]);

        $trade = P2PTrade::findOrFail($id);

        if (!in_array($trade->status, ['active', 'processing'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot cancel trade with current status'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Refund logic based on trade type and status
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
            } else if ($trade->status === 'active' && $trade->type === 'sell') {
                // Refund locked CMEME tokens to seller
                User::where('id', $trade->seller_id)->increment('token_balance', $trade->amount);
            }

            $trade->update([
                'status' => 'cancelled',
                // 'cancelled_at' => now(),
                // 'cancelled_by' => auth()->id(),
                // 'cancellation_reason' => "Admin cancelled: {$request->reason}"
            ]);

            // Create system message
            \App\Models\P2PTradeMessage::create([
                'trade_id' => $trade->id,
                'user_id' => auth()->id(),
                'message' => "Trade cancelled by admin. Reason: {$request->reason}",
                'type' => 'system',
                'is_system' => true
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Trade cancelled successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to cancel trade: ' . $e->getMessage()
            ], 500);
        }
    }

    public function exportTrades(Request $request)
    {
        $query = P2PTrade::with(['seller', 'buyer']);

        if ($request->start_date) {
            $query->where('created_at', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $query->where('created_at', '<=', $request->end_date);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $trades = $query->orderBy('created_at', 'desc')->get();

        $csvData = [];
        $csvData[] = [
            'ID', 'Type', 'Status', 'Amount', 'Price', 'Total', 
            'Seller', 'Buyer', 'Payment Method', 'Created At', 
            'Completed At', 'Cancelled At'
        ];

        foreach ($trades as $trade) {
            $csvData[] = [
                $trade->id,
                $trade->type,
                $trade->status,
                $trade->amount,
                $trade->price,
                $trade->total,
                $trade->seller->username,
                $trade->buyer ? $trade->buyer->username : 'N/A',
                $trade->payment_method,
                $trade->created_at,
                $trade->completed_at,
                $trade->cancelled_at
            ];
        }

        $filename = "p2p_trades_export_" . date('Y-m-d_H-i-s') . ".csv";

        $handle = fopen('php://output', 'w');
        fputs($handle, "\xEF\xBB\xBF"); // UTF-8 BOM

        foreach ($csvData as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return response()->streamDownload(function() use ($csvData) {
            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF");
            foreach ($csvData as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function getUserP2PStats($userId)
    {
        $user = User::withCount([
            'p2pTrades as total_trades',
            'p2pTrades as completed_trades' => function($query) {
                $query->where('status', 'completed');
            },
            'p2pTrades as disputed_trades' => function($query) {
                $query->where('status', 'disputed');
            },
            'p2pTrades as cancelled_trades' => function($query) {
                $query->where('status', 'cancelled');
            }
        ])->findOrFail($userId);

        $successRate = $user->completed_trades > 0 
            ? round(($user->completed_trades / $user->total_trades) * 100, 2)
            : 100;

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user,
                'p2p_stats' => [
                    'total_trades' => $user->total_trades,
                    'completed_trades' => $user->completed_trades,
                    'disputed_trades' => $user->disputed_trades,
                    'cancelled_trades' => $user->cancelled_trades,
                    'success_rate' => $successRate,
                    'avg_completion_time' => null, // You can calculate this based on your needs
                ]
            ]
        ]);
    }
}