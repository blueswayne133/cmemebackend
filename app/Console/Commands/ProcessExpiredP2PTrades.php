<?php
// app/Console/Commands/ProcessExpiredP2PTrades.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\P2PTrade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessExpiredP2PTrades extends Command
{
    protected $signature = 'p2p:process-expired';
    protected $description = 'Process expired P2P trades and refund tokens';

    public function handle()
    {
        $this->info('Processing expired P2P trades...');
        
        $expiredTrades = P2PTrade::where('status', 'processing')
            ->where('expires_at', '<', now())
            ->with(['seller', 'buyer'])
            ->get();

        $processedCount = 0;

        foreach ($expiredTrades as $trade) {
            try {
                DB::transaction(function () use ($trade) {
                    // Refund tokens based on trade type
                    if ($trade->type === 'sell') {
                        // SELL ORDER: Refund CMEME to seller
                        $trade->seller->increment('token_balance', $trade->amount);
                    } else {
                        // BUY ORDER: Refund CMEME to buyer
                        if ($trade->buyer_id) {
                            $trade->buyer->increment('token_balance', $trade->amount);
                        }
                    }

                    $trade->update([
                        'status' => 'cancelled',
                        'cancellation_reason' => 'Trade expired automatically - no payment received',
                        'cancelled_at' => now(),
                    ]);

                    // Create system message
                    \App\Models\P2PTradeMessage::create([
                        'trade_id' => $trade->id,
                        'user_id' => $trade->seller_id,
                        'message' => "Trade expired automatically. Tokens have been refunded.",
                        'type' => 'system',
                        'is_system' => true
                    ]);
                });

                $processedCount++;
                $this->info("Processed expired trade #{$trade->id}");

            } catch (\Exception $e) {
                Log::error("Failed to process expired trade {$trade->id}: " . $e->getMessage());
                $this->error("Failed to process trade #{$trade->id}: " . $e->getMessage());
            }
        }

        $this->info("Successfully processed {$processedCount} expired trades.");
        
        if ($processedCount > 0) {
            Log::info("Processed {$processedCount} expired P2P trades");
        }
        
        return 0;
    }
}