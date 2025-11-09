<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminKycController extends Controller
{
    /**
     * Get all KYC verifications with filters
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $page = $request->get('page', 1);

        $query = KycVerification::with(['user:id,username,email,created_at'])
            ->latest();

        // Apply status filter
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Apply document type filter
        if ($request->has('document_type') && $request->document_type !== 'all') {
            $query->where('document_type', $request->document_type);
        }

        // Apply search filter
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('document_number', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('username', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $kycList = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform the data
        $transformedData = $kycList->getCollection()->map(function ($kyc) {
            return [
                'id' => $kyc->id,
                'user_id' => $kyc->user_id,
                'user' => $kyc->user ? [
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
                'admin_id' => $kyc->admin_id,
                'verified_by_admin_at' => $kyc->verified_by_admin_at,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $transformedData,
            'meta' => [
                'current_page' => $kycList->currentPage(),
                'last_page' => $kycList->lastPage(),
                'per_page' => $kycList->perPage(),
                'total' => $kycList->total(),
                'from' => $kycList->firstItem(),
                'to' => $kycList->lastItem(),
            ]
        ]);
    }

    /**
     * Get KYC statistics for dashboard
     */
    public function getStats()
    {
        $total = KycVerification::count();
        $pending = KycVerification::pending()->count();
        $verified = KycVerification::verified()->count();
        $rejected = KycVerification::rejected()->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total' => $total,
                'pending' => $pending,
                'verified' => $verified,
                'rejected' => $rejected,
            ]
        ]);
    }

    /**
     * Get specific KYC verification details
     */
    public function show($id)
    {
        $kyc = KycVerification::with(['user', 'admin'])->find($id);

        if (!$kyc) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $kyc->id,
                'user_id' => $kyc->user_id,
                'user' => $kyc->user ? [
                    'id' => $kyc->user->id,
                    'username' => $kyc->user->username,
                    'email' => $kyc->user->email,
                    'created_at' => $kyc->user->created_at,
                    'kyc_status' => $kyc->user->kyc_status,
                    'is_verified' => $kyc->user->is_verified,
                ] : null,
                'admin' => $kyc->admin ? [
                    'id' => $kyc->admin->id,
                    'name' => $kyc->admin->name,
                    'email' => $kyc->admin->email,
                ] : null,
                'document_type' => $kyc->document_type,
                'document_type_label' => $kyc->getDocumentTypeLabel(),
                'document_number' => $kyc->document_number,
                'document_front_path' => $kyc->document_front_path,
                'document_back_path' => $kyc->document_back_path,
                'status' => $kyc->status,
                'rejection_reason' => $kyc->rejection_reason,
                'verification_notes' => $kyc->verification_notes,
                'verification_score' => $kyc->verification_score,
                'verification_details' => $kyc->verification_details,
                'submitted_at' => $kyc->submitted_at,
                'verified_at' => $kyc->verified_at,
                'verified_by_admin_at' => $kyc->verified_by_admin_at,
            ]
        ]);
    }

    /**
     * Manually approve KYC verification
     */
    public function approveKyc(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $kyc = KycVerification::with('user')->find($id);

        if (!$kyc) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification not found'
            ], 404);
        }

        if ($kyc->status === 'verified') {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC is already verified'
            ], 400);
        }

        try {
            $admin = $request->user();

            // Update KYC status
            $kyc->markAsVerified($admin->id, $request->notes);

            // Update user KYC status
            if ($kyc->user) {
                $kyc->user->updateKycStatus('verified', $kyc);
                
                // Process referral rewards if applicable
                if ($kyc->user->referred_by) {
                    $this->processReferralRewards($kyc->user);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'KYC verification approved successfully',
                'data' => [
                    'kyc_status' => $kyc->status,
                    'verified_at' => $kyc->verified_at,
                    'verified_by_admin_at' => $kyc->verified_by_admin_at,
                    'admin_name' => $admin->name,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve KYC: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manually reject KYC verification
     */
    public function rejectKyc(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $kyc = KycVerification::with('user')->find($id);

        if (!$kyc) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification not found'
            ], 404);
        }

        if ($kyc->status === 'rejected') {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC is already rejected'
            ], 400);
        }

        try {
            $admin = $request->user();

            // Update KYC status
            $kyc->markAsRejected($admin->id, $request->reason, $request->notes);

            // Update user KYC status
            if ($kyc->user) {
                $kyc->user->updateKycStatus('rejected', $kyc);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'KYC verification rejected successfully',
                'data' => [
                    'kyc_status' => $kyc->status,
                    'rejection_reason' => $kyc->rejection_reason,
                    'verified_at' => $kyc->verified_at,
                    'verified_by_admin_at' => $kyc->verified_by_admin_at,
                    'admin_name' => $admin->name,
                ]
            ]);

        } catch (\Exception $e) {
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
        $kyc = KycVerification::find($id);

        if (!$kyc) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification not found'
            ], 404);
        }

        try {
            $kyc->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'KYC verification deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete KYC: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get KYC document image
     */
    public function getDocument($id, $documentType)
    {
        $kyc = KycVerification::find($id);

        if (!$kyc) {
            return response()->json([
                'status' => 'error',
                'message' => 'KYC verification not found'
            ], 404);
        }

        $path = null;
        if ($documentType === 'front') {
            $path = $kyc->document_front_path;
        } elseif ($documentType === 'back') {
            $path = $kyc->document_back_path;
        }

        if (!$path) {
            return response()->json([
                'status' => 'error',
                'message' => 'Document not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'document_url' => $path
            ]
        ]);
    }

    /**
     * Process referral rewards for verified user
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
                $referrer->increment('referral_token_balance', $cmemeReward);
                
                // Add USDC to referrer's pending balance (requires claiming)
                $referrer->increment('referral_usdc_balance', $usdcReward);

                // Create transaction for CMEME reward
                \App\Models\Transaction::create([
                    'user_id' => $referrer->id,
                    'type' => \App\Models\Transaction::TYPE_DEPOSIT,
                    'amount' => $cmemeReward,
                    'currency' => 'CMEME',
                    'status' => 'completed',
                    'description' => 'Referral reward from ' . $user->username,
                ]);

                // Call the referral controller to update overall stats
                if (method_exists(\App\Http\Controllers\ReferralController::class, 'updateReferralRewards')) {
                    \App\Http\Controllers\ReferralController::updateReferralRewards($user);
                }
            }
        }
    }
}