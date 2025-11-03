<?php

namespace App\Events;

use App\Models\P2PTrade;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class P2PTradeUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $trade;

    // public function __construct(P2PTrade $trade)
    // {
    //     $this->trade = $trade->load(['seller', 'buyer', 'proofs', 'dispute']);
    // }

    // public function broadcastOn()
    // {
    //     return new Channel('p2p-trades');
    // }

    // public function broadcastAs()
    // {
    //     return 'P2PTradeUpdated';
    // }

    // public function broadcastWith()
    // {
    //     return [
    //         'trade' => $this->trade,
    //         'timestamp' => now()->toISOString()
    //     ];
    // }
}