<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MiningController extends Controller
{
    public function claimMiningReward(Request $request)
    {
        $user = $request->user();

        try {
            DB::transaction(function () use ($user) {
                // Check if user can claim (24-hour cooldown)
                $lastClaim = Transaction::where('user_id', $user->id)
                    ->where('type', Transaction::TYPE_MINING)
                    ->where('amount', '>', 0)
                    ->latest()
                    ->first();

                if ($lastClaim && Carbon::parse($lastClaim->created_at)->diffInHours(now()) < 24) {
                    $hoursRemaining = 24 - Carbon::parse($lastClaim->created_at)->diffInHours(now());
                    throw new \Exception("You can claim again in " . round($hoursRemaining, 1) . " hours.");
                }

                // Add 1 CMEME to user balance
                $user->increment('token_balance', 1);

                // Create transaction for the reward
                Transaction::create([
                    'user_id' => $user->id,
                    'type' => Transaction::TYPE_MINING,
                    'amount' => 1,
                    'description' => 'Daily mining reward claimed',
                    'metadata' => [
                        'reward_type' => 'mining',
                        'claimed_at' => now()->toISOString(),
                    ],
                ]);
            });

            return response()->json([
                'status' => 'success',
                'message' => '1 CMEME claimed successfully!',
                'data' => [
                    'user' => $user->fresh()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getMiningStatus(Request $request)
    {
        $user = $request->user();

        // Get last mining claim
        $lastClaim = Transaction::where('user_id', $user->id)
            ->where('type', Transaction::TYPE_MINING)
            ->where('amount', '>', 0)
            ->latest()
            ->first();

        $canClaim = true;
        $timeRemaining = 0;
        $lastClaimedAt = null;
        $progress = 0;

        if ($lastClaim) {
            $lastClaimedAt = $lastClaim->created_at;
            $hoursSinceLastClaim = Carbon::parse($lastClaimedAt)->diffInHours(now());
            $secondsSinceLastClaim = Carbon::parse($lastClaimedAt)->diffInSeconds(now());
            
            if ($hoursSinceLastClaim < 24) {
                $canClaim = false;
                $timeRemaining = (24 - $hoursSinceLastClaim) * 3600; // Convert to seconds
                
                // Calculate progress percentage (0% to 100%)
                $totalTime = 86400; // 24 hours in seconds
                $progress = min(100, ($secondsSinceLastClaim / $totalTime) * 100);
            } else {
                // Mining complete, ready to claim
                $canClaim = true;
                $progress = 100;
            }
        } else {
            // Never claimed before, can claim immediately
            $canClaim = true;
            $progress = 100;
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'can_claim' => $canClaim,
                'last_claimed_at' => $lastClaimedAt,
                'time_remaining' => $timeRemaining,
                'progress' => $progress,
                'next_claim_at' => $lastClaimedAt ? Carbon::parse($lastClaimedAt)->addHours(24) : null,
            ]
        ]);
    }
}