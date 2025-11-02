<?php

namespace App\Events;

use App\Models\P2PTrade;
use App\Models\P2PTradeMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewTradeMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $trade;
    public $message;

    public function __construct(P2PTrade $trade, $message)
    {
        $this->trade = $trade;
        $this->message = $message;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('p2p-trade.' . $this->trade->id);
    }

    public function broadcastAs()
    {
        return 'NewTradeMessage';
    }

    public function broadcastWith()
    {
        return [
            'message' => $this->message,
            'trade_id' => $this->trade->id,
            'timestamp' => now()->toISOString()
        ];
    }
}