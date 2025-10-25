<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function pending()
    {
        $kycRequests = KycVerification::with(['user'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $kycRequests
        ]);
    }

    public function approve($id)
    {
        $kyc = KycVerification::findOrFail($id);
        
        $kyc->update([
            'status' => 'approved',
            'verified_at' => now(),
            'admin_id' => auth()->id()
        ]);

        // Update user verification status
        $kyc->user->update([
            'is_verified' => true,
            'kyc_verified_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'KYC approved successfully'
        ]);
    }

    public function reject($id, Request $request)
    {
        $request->validate([
            'reason' => 'required|string'
        ]);

        $kyc = KycVerification::findOrFail($id);
        
        $kyc->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
            'verified_at' => now(),
            'admin_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'KYC rejected successfully'
        ]);
    }
}