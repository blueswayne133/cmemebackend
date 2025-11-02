<?php

use App\Models\P2PTrade;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Public channel for all trade updates
Broadcast::channel('p2p-trades', function ($user) {
    return ['id' => $user->id, 'name' => $user->username];
});

// Private channel for specific trade (only participants)
Broadcast::channel('p2p-trade.{tradeId}', function ($user, $tradeId) {
    $trade = P2PTrade::find($tradeId);
    
    if (!$trade) {
        return false;
    }
    
    // Allow only trade participants
    if ($trade->seller_id === $user->id || $trade->buyer_id === $user->id) {
        return [
            'id' => $user->id, 
            'name' => $user->username,
            'trade_id' => $tradeId
        ];
    }
    
    return false;
});