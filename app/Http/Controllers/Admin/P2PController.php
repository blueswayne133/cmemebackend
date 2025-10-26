<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\P2PTrade;
use App\Models\P2PDispute;
use App\Models\User;
use Illuminate\Http\Request;

class P2PController extends Controller
{
    public function getTrades(Request $request)
    {
        $query = P2PTrade::with(['seller', 'buyer', 'dispute'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        $trades = $query->paginate(20);

        $stats = [
            'total' => P2PTrade::count(),
            'active' => P2PTrade::where('status', 'active')->count(),
            'processing' => P2PTrade::where('status', 'processing')->count(),
            'completed' => P2PTrade::where('status', 'completed')->count(),
            'cancelled' => P2PTrade::where('status', 'cancelled')->count(),
            'total_volume' => P2PTrade::where('status', 'completed')->sum('total'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'trades' => $trades,
                'stats' => $stats
            ]
        ]);
    }

    public function getTrade($id)
    {
        $trade = P2PTrade::with([
            'seller', 
            'buyer', 
            'proofs', 
            'dispute',
            'dispute.raisedBy',
            'dispute.resolvedBy'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $trade
        ]);
    }

    public function getDisputes(Request $request)
    {
        $query = P2PDispute::with(['trade', 'raisedBy', 'resolvedBy'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $disputes = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $disputes
        ]);
    }

    public function resolveDispute(Request $request, $id)
    {
        $request->validate([
            'resolution' => 'required|string|max:1000',
            'award_seller' => 'nullable|numeric|min:0',
            'award_buyer' => 'nullable|numeric|min:0',
        ]);

        $dispute = P2PDispute::with(['trade'])->findOrFail($id);
        
        $dispute->markAsResolved(
            $request->resolution,
            auth()->user()
        );

        // Handle fund distribution if specified
        if ($request->award_seller || $request->award_buyer) {
            // Implement fund distribution logic here
            // This would typically involve updating user balances
            // and creating transactions
        }

        return response()->json([
            'success' => true,
            'message' => 'Dispute resolved successfully'
        ]);
    }

    public function cancelTrade($id, Request $request)
    {
        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        $trade = P2PTrade::findOrFail($id);
        $trade->markAsCancelled($request->reason);

        return response()->json([
            'success' => true,
            'message' => 'Trade cancelled successfully'
        ]);
    }
}