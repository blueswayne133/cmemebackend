<?php
// app/Http/Controllers/Admin/AdminKycController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycVerification;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminKycController extends Controller
{
    /**
     * Get all KYC verifications with filters
     */
    public function index(Request $request)
    {
        $query = KycVerification::with(['user', 'admin'])
            ->latest();

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by document type
        if ($request->has('document_type') && $request->document_type !== 'all') {
            $query->where('document_type', $request->document_type);
        }

        // Search
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('document_number', 'like', "%{$search}%")
                  ->orWhereHas('user', function($userQuery) use ($search) {
                      $userQuery->where('username', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $kycList = $query->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $kycList->map(function($kyc) {
                return [
                    'id' => $kyc->id,
                    'user_id' => $kyc->user_id,
                    'user' => $kyc->user ? [
                        'id' => $kyc->user->id,
                        'username' => $kyc->user->username,
                        'email' => $kyc->user->email,
                        'created_at' => $kyc->user->created_at,
                    ] : null,
                    'document_type' => $kyc->document_type,
                    'document_type_label' => $kyc->getDocumentTypeLabel(),
                    'document_number' => $kyc->document_number,
                    'status' => $kyc->status,
                    'submitted_at' => $kyc->submitted_at,
                    'verified_at' => $kyc->verified_at,
                    'rejection_reason' => $kyc->rejection_reason,
                    'verification_score' => $kyc->verification_score,
                    'verification_details' => $kyc->verification_details,
                    'verification_notes' => $kyc->verification_notes,
                    'admin' => $kyc->admin ? [
                        'id' => $kyc->admin->id,
                        'name' => $kyc->admin->name,
                    ] : null,
                ];
            }),
            'meta' => [
                'current_page' => $kycList->currentPage(),
                'last_page' => $kycList->lastPage(),
                'per_page' => $kycList->perPage(),
                'total' => $kycList->total(),
            ]
        ]);
    }

    /**
     * Get pending KYC verifications
     */
    public function pending(Request $request)
    {
        $query = KycVerification::with(['user'])
            ->pending()
            ->latest();

        $pendingKyc = $query->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $pendingKyc
        ]);
    }

    /**
     * Get specific KYC verification details
     */
    public function show($id)
    {
        $kyc = KycVerification::with(['user', 'admin'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $kyc->id,
                'user_id' => $kyc->user_id,
                'user' => $kyc->user ? [
                    'id' => $kyc->user->id,
                    'username' => $kyc->user->username,
                    'email' => $kyc->user->email,
                    'first_name' => $kyc->user->first_name,
                    'last_name' => $kyc->user->last_name,
                    'created_at' => $kyc->user->created_at,
                    'kyc_status' => $kyc->user->kyc_status,
                    'kyc_verified_at' => $kyc->user->kyc_verified_at,
                ] : null,
                'document_type' => $kyc->document_type,
                'document_type_label' => $kyc->getDocumentTypeLabel(),
                'document_number' => $kyc->document_number,
                'document_front_path' => $kyc->document_front_path,
                'document_back_path' => $kyc->document_back_path,
                'status' => $kyc->status,
                'submitted_at' => $kyc->submitted_at,
                'verified_at' => $kyc->verified_at,
                'verified_by_admin_at' => $kyc->verified_by_admin_at,
                'rejection_reason' => $kyc->rejection_reason,
                'verification_score' => $kyc->verification_score,
                'verification_details' => $kyc->verification_details,
                'verification_notes' => $kyc->verification_notes,
                'admin' => $kyc->admin ? [
                    'id' => $kyc->admin->id,
                    'name' => $kyc->admin->name,
                    'email' => $kyc->admin->email,
                ] : null,
            ]
        ]);
    }

    /**
     * Approve KYC verification
     */
    public function approve(Request $request, $id)
    {
        $kyc = KycVerification::with(['user'])->findOrFail($id);
        $admin = $request->user();

        if ($kyc->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC is not pending approval'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Mark KYC as verified
            $kyc->markAsVerified($admin->id, 'Approved by admin');

            // Update user KYC status
            $kyc->user->updateKycStatus('verified', $kyc);

            // Process referral rewards if applicable
            $this->processReferralRewards($kyc->user);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'KYC approved successfully',
                'data' => [
                    'kyc_status' => $kyc->status,
                    'user_kyc_status' => $kyc->user->kyc_status,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve KYC: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject KYC verification
     */
    public function reject(Request $request, $id)
    {
        $kyc = KycVerification::with(['user'])->findOrFail($id);
        $admin = $request->user();

        if ($kyc->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC is not pending approval'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500'
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

            // Mark KYC as rejected
            $kyc->markAsRejected($admin->id, $request->reason, 'Rejected by admin');

            // Update user KYC status
            $kyc->user->updateKycStatus('rejected', $kyc);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'KYC rejected successfully',
                'data' => [
                    'kyc_status' => $kyc->status,
                    'user_kyc_status' => $kyc->user->kyc_status,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject KYC: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete KYC verification
     */
    public function destroy($id)
    {
        $kyc = KycVerification::with(['user'])->findOrFail($id);

        try {
            DB::beginTransaction();

            // Delete document files from storage
            if ($kyc->document_front_path && Storage::exists('public/' . $kyc->document_front_path)) {
                Storage::delete('public/' . $kyc->document_front_path);
            }
            if ($kyc->document_back_path && Storage::exists('public/' . $kyc->document_back_path)) {
                Storage::delete('public/' . $kyc->document_back_path);
            }

            // If this is the user's current KYC, reset user's KYC status
            if ($kyc->user && $kyc->user->current_kyc_id == $kyc->id) {
                $kyc->user->update([
                    'current_kyc_id' => null,
                    'kyc_status' => 'not_submitted',
                    'kyc_verified_at' => null,
                    'is_verified' => false
                ]);
            }

            // Delete the KYC record
            $kyc->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'KYC submission deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete KYC: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user KYC history
     */
    public function getUserKycHistory($userId)
    {
        $user = User::findOrFail($userId);

        $kycHistory = $user->kycVerifications()
            ->with(['admin'])
            ->latest()
            ->paginate(10);

        return response()->json([
            'status' => 'success',
            'data' => $kycHistory
        ]);
    }

    /**
     * Get KYC document image
     */
    public function getDocument($id, $type)
    {
        $kyc = KycVerification::findOrFail($id);

        $path = null;
        if ($type === 'front') {
            $path = $kyc->document_front_path;
        } elseif ($type === 'back') {
            $path = $kyc->document_back_path;
        }

        if (!$path || !Storage::exists('public/' . $path)) {
            abort(404, 'Document not found');
        }

        return response()->file(storage_path('app/public/' . $path));
    }

    /**
     * Process referral rewards when KYC is approved
     */
    private function processReferralRewards(User $user)
    {
        if ($user->isKycVerified() && $user->referred_by) {
            $referrer = User::find($user->referred_by);
            
            if ($referrer) {
                // Reward amounts
                $cmemeReward = 0.5;
                $usdcReward = 0.1;

                // Add CMEME tokens immediately to referrer's balance
                $referrer->increment('token_balance', $cmemeReward);
                
                // Add USDC to referrer's pending balance (requires claiming)
                $referrer->increment('referral_usdc_balance', $usdcReward);

                // Create transaction for CMEME reward
                Transaction::create([
                    'user_id' => $referrer->id,
                    'type' => Transaction::TYPE_REFERRAL,
                    'amount' => $cmemeReward,
                    'description' => 'Referral reward from ' . $user->username . ' (KYC Verified)',
                    'metadata' => [
                        'referred_user_id' => $user->id,
                        'reward_type' => 'kyc_verification',
                        'currency' => 'CMEME',
                    ],
                ]);

                // Update referral stats
                \App\Http\Controllers\ReferralController::updateReferralRewards($user);
            }
        }
    }
}