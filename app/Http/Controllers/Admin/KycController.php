<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KycController extends Controller
{
    public function index(Request $request)
    {
        $query = KycVerification::with(['user', 'admin'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $kycRequests = $query->paginate(20);

        $stats = [
            'pending' => KycVerification::where('status', 'pending')->count(),
            'verified' => KycVerification::where('status', 'verified')->count(),
            'rejected' => KycVerification::where('status', 'rejected')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'kyc_requests' => $kycRequests,
                'stats' => $stats
            ]
        ]);
    }

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

    public function show($id)
    {
        $kyc = KycVerification::with(['user', 'admin'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $kyc
        ]);
    }

    public function approve($id, Request $request)
    {
        $request->validate([
            'verification_notes' => 'nullable|string|max:500'
        ]);

        $kyc = KycVerification::findOrFail($id);
        
        $kyc->markAsVerified(
            auth()->id(),
            $request->verification_notes
        );

        // Update user verification status
        $kyc->user->updateKycStatus('verified', $kyc);

        return response()->json([
            'success' => true,
            'message' => 'KYC approved successfully'
        ]);
    }

    public function reject($id, Request $request)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:500',
            'verification_notes' => 'nullable|string|max:500'
        ]);

        $kyc = KycVerification::findOrFail($id);
        
        $kyc->markAsRejected(
            auth()->id(),
            $request->rejection_reason,
            $request->verification_notes
        );

        // Update user verification status
        $kyc->user->updateKycStatus('rejected', $kyc);

        return response()->json([
            'success' => true,
            'message' => 'KYC rejected successfully'
        ]);
    }

    public function getUserKycHistory($userId)
    {
        $user = User::findOrFail($userId);
        $kycHistory = KycVerification::where('user_id', $userId)
            ->with(['admin'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'kyc_history' => $kycHistory
            ]
        ]);
    }

    public function getDocument($id, $type)
    {
        $kyc = KycVerification::findOrFail($id);
        
        $filePath = $type === 'front' ? $kyc->document_front_path : $kyc->document_back_path;
        
        if (!$filePath || !Storage::exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found'
            ], 404);
        }

        return response()->file(Storage::path($filePath));
    }
}