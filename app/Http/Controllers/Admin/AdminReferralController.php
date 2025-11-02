<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminReferralController extends Controller
{
    /**
     * Get all users with referral statistics
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 20);
            $search = $request->get('search');
            $status = $request->get('status');

            $query = User::withCount([
                'referrals as total_referrals_count',
                'referrals as verified_referrals_count' => function ($query) {
                    $query->where('kyc_status', 'verified');
                },
            ])
            ->withSum('referrals as total_referral_usdc', 'referral_usdc_balance')
            ->where(function ($q) {
                $q->whereHas('referrals')
                  ->orWhere('referral_usdc_balance', '>', 0);
            });

            // Search filter
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('referral_code', 'like', "%{$search}%");
                });
            }

            // Status filter
            if ($status === 'with_pending_usdc') {
                $query->where('referral_usdc_balance', '>', 0);
            } elseif ($status === 'can_claim') {
                $query->where('can_claim_referral_usdc', true);
            } elseif ($status === 'cannot_claim') {
                $query->where('can_claim_referral_usdc', false);
            }

            $users = $query->orderByDesc('total_referral_usdc')->paginate($perPage);

            // Format response
            $users->getCollection()->transform(function ($user) {
                return [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'referral_code' => $user->referral_code,
                    'total_referrals' => $user->total_referrals_count ?? 0,
                    'verified_referrals' => $user->verified_referrals_count ?? 0,
                    'pending_usdc_balance' => $user->referral_usdc_balance ?? 0,
                    'can_claim_referral_usdc' => (bool) $user->can_claim_referral_usdc,
                    'total_earned_usdc' => $user->total_referral_usdc ?? 0,
                    'created_at' => $user->created_at,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $users,
            ]);
        } catch (\Exception $e) {
            Log::error('Admin referral index error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch referral data',
            ], 500);
        }
    }

    /**
     * Get detailed referral stats for a specific user
     */
    public function getUserReferralStats($userId)
    {
        try {
            $user = User::withCount([
                'referrals as total_referrals_count',
                'referrals as verified_referrals_count' => function ($query) {
                    $query->where('kyc_status', 'verified');
                },
                'referrals as pending_referrals_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->where('kyc_status', '!=', 'verified')
                          ->orWhereNull('kyc_status');
                    });
                },
            ])
            ->with(['referrals' => function ($query) {
                $query->select('id', 'username', 'email', 'kyc_status', 'referred_by', 'created_at')
                      ->orderByDesc('created_at')
                      ->limit(10);
            }])
            ->findOrFail($userId);

            $stats = [
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'referral_code' => $user->referral_code,
                    'can_claim_referral_usdc' => (bool) $user->can_claim_referral_usdc,
                ],
                'stats' => [
                    'total_referrals' => $user->total_referrals_count ?? 0,
                    'verified_referrals' => $user->verified_referrals_count ?? 0,
                    'pending_referrals' => $user->pending_referrals_count ?? 0,
                    'pending_usdc_balance' => $user->referral_usdc_balance ?? 0,
                    'total_usdc_earned' => $user->total_referral_usdc ?? 0, // Placeholder for now
                ],
                'recent_referrals' => $user->referrals,
            ];

            return response()->json([
                'status' => 'success',
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Admin getUserReferralStats error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch user referral stats',
            ], 500);
        }
    }

    /**
     * Toggle USDC claiming for a user
     */
    public function toggleUsdcClaiming($userId)
    {
        try {
            $user = User::findOrFail($userId);

            $user->can_claim_referral_usdc = !$user->can_claim_referral_usdc;
            $user->save();

            $action = $user->can_claim_referral_usdc ? 'enabled' : 'disabled';

            return response()->json([
                'status' => 'success',
                'message' => "USDC claiming {$action} for user {$user->username}",
                'data' => [
                    'can_claim_referral_usdc' => $user->can_claim_referral_usdc,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Admin toggleUsdcClaiming error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update USDC claiming status',
            ], 500);
        }
    }

    /**
     * Bulk update USDC claiming status
     */
    public function bulkUpdateUsdcClaiming(Request $request)
    {
        try {
            $request->validate([
                'user_ids' => 'required|array',
                'user_ids.*' => 'exists:users,id',
                'status' => 'required|boolean',
            ]);

            $updatedCount = User::whereIn('id', $request->user_ids)
                ->update(['can_claim_referral_usdc' => $request->status]);

            return response()->json([
                'status' => 'success',
                'message' => "USDC claiming " . ($request->status ? 'enabled' : 'disabled') . " for {$updatedCount} users",
                'data' => [
                    'updated_count' => $updatedCount,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Admin bulkUpdateUsdcClaiming error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to bulk update USDC claiming status',
            ], 500);
        }
    }

    /**
     * Get platform-wide referral statistics
     */
    public function getPlatformReferralStats()
    {
        try {
            // Using Eloquent aggregate instead of subquery for better performance
            $totalUsersWithReferrals = User::whereHas('referrals')->count();
            $totalPendingUsdc = User::sum('referral_usdc_balance');
            $usersWithPendingUsdc = User::where('referral_usdc_balance', '>', 0)->count();
            $usersCanClaim = User::where('can_claim_referral_usdc', true)->count();
            $totalReferralsGenerated = User::has('referrals')->withCount('referrals')->get()->sum('referrals_count');

            $stats = [
                'total_users_with_referrals' => $totalUsersWithReferrals,
                'total_pending_usdc' => $totalPendingUsdc,
                'users_with_pending_usdc' => $usersWithPendingUsdc,
                'users_can_claim' => $usersCanClaim,
                'total_referrals_generated' => $totalReferralsGenerated,
            ];

            return response()->json([
                'status' => 'success',
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Admin getPlatformReferralStats error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch platform referral stats',
            ], 500);
        }
    }
}
