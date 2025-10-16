<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\P2PController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('profile')->group(function () {
    Route::get('/', [ProfileController::class, 'getProfile']);
      });

 // kyc routes
    Route::prefix('kyc')->group(function () {
    Route::post('/submit', [KycController::class, 'submitKyc']);
    Route::get('/status', [KycController::class, 'getKycStatus']);
    Route::get('/history', [KycController::class, 'getKycHistory']);
    
     });
    // Referral routes
    Route::prefix('referrals')->group(function () {
    Route::get('/stats', [ReferralController::class, 'getReferralStats']);
    Route::post('/claim', [ReferralController::class, 'claimReferralRewards']);
    });


    // P2P Trading routes
    Route::prefix('p2p')->group(function () {
    Route::get('/trades', [P2PController::class, 'getTrades']);
    Route::post('/trades', [P2PController::class, 'createTrade']);
    Route::get('/trades/user', [P2PController::class, 'getUserTrades']);
    Route::get('/trades/{tradeId}', [P2PController::class, 'getTradeDetails']);
    Route::post('/trades/{tradeId}/initiate', [P2PController::class, 'initiateTrade']);
    Route::post('/trades/{tradeId}/upload-proof', [P2PController::class, 'uploadPaymentProof']);
    Route::post('/trades/{tradeId}/confirm-payment', [P2PController::class, 'confirmPayment']);
    Route::post('/trades/{tradeId}/cancel', [P2PController::class, 'cancelTrade']);
    Route::post('/trades/{tradeId}/dispute', [P2PController::class, 'createDispute']);
    });



    Route::prefix('leaderboard')->group(function () {
    Route::get('/', [LeaderboardController::class, 'getLeaderboard']);
    });

    
    // // Mining routes
    // Route::prefix('mining')->group(function () {
    //     Route::post('/start', [MiningController::class, 'startMining']);
    //     Route::post('/claim', [MiningController::class, 'claimReward']);
    //     Route::get('/status', [MiningController::class, 'getMiningStatus']);
    // });

    // // Wallet routes
    // Route::prefix('wallet')->group(function () {
    //     Route::get('/balance', [WalletController::class, 'getBalance']);
    //     Route::post('/fund/cmeme', [WalletController::class, 'fundWithCMEME']);
    //     Route::post('/send', [WalletController::class, 'sendTokens']);
    //     Route::get('/transactions', [WalletController::class, 'getTransactions']);
    // });

    // // Task routes
    // Route::prefix('tasks')->group(function () {
    //     Route::get('/', [TaskController::class, 'getTasks']);
    //     Route::post('/{task}/complete', [TaskController::class, 'completeTask']);
    // });
});