<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MiningController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

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