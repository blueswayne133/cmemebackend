<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\P2PController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MiningController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WalletController;

// Admin Controllers
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\KycController as AdminKycController;
use App\Http\Controllers\Admin\AdminTransactionController;
use App\Http\Controllers\Admin\SettingsController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/verify-2fa', [AuthController::class, 'verifyTwoFactor']);
Route::post('/resend-2fa', [AuthController::class, 'resendTwoFactorCode']);
Route::post('/2fa-methods', [AuthController::class, 'getTwoFactorMethods']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::get('/platform-stats', [AuthController::class, 'getPlatformStats']);

// Public verification route (no auth required)
Route::get('/transactions/verify-transfer/{token}', [TransactionController::class, 'verifyTransfer']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/user/update-avatar', [ProfileController::class, 'updateAvatar']);


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

   Route::prefix('tasks')->group(function () {
    Route::get('/', [TaskController::class, 'getTasks']);
    Route::post('/{task}/complete', [TaskController::class, 'completeTask']);
    Route::get('/stats', [TaskController::class, 'getTaskStats']);
   });

       // Mining routes
    Route::prefix('mining')->group(function () {
        Route::post('/claim', [MiningController::class, 'claimMiningReward']);
        Route::get('/status', [MiningController::class, 'getMiningStatus']);
    });


 Route::prefix('wallet')->group(function () {
    Route::post('/connect', [WalletController::class, 'connectWallet']);
    Route::post('/update', [WalletController::class, 'updateWallet']);
    Route::post('/disconnect', [WalletController::class, 'disconnectWallet']);
    Route::post('/claim-bonus', [WalletController::class, 'claimWalletBonus']);
    Route::get('/status', [WalletController::class, 'getWalletStatus']);
});


Route::prefix('security')->group(function () {
    Route::get('/settings', [SecurityController::class, 'getSecuritySettings']);
    Route::post('/update-phone', [SecurityController::class, 'updatePhone']);
    Route::post('/verify-phone', [SecurityController::class, 'verifyPhone']);
    Route::post('/setup-authenticator', [SecurityController::class, 'setupAuthenticator']);
    Route::post('/verify-authenticator', [SecurityController::class, 'verifyAuthenticator']);
    Route::post('/enable-email-2fa', [SecurityController::class, 'enableEmail2FA']);
    Route::post('/verify-email-2fa', [SecurityController::class, 'verifyEmail2FA']);
    Route::post('/enable-sms-2fa', [SecurityController::class, 'enableSMS2FA']);
    Route::post('/verify-sms-2fa', [SecurityController::class, 'verifySMS2FA']);
    Route::post('/disable-2fa', [SecurityController::class, 'disable2FA']);
    Route::post('/change-password', [SecurityController::class, 'changePassword']);
    Route::post('/generate-backup-codes', [SecurityController::class, 'generateBackupCodes']);
});



// Add to api.php routes
Route::prefix('transactions')->group(function () {
Route::get('/', [TransactionController::class, 'getUserTransactions']);
Route::post('/send', [TransactionController::class, 'sendTokens']);
Route::get('/verify-transfer/{token}', [TransactionController::class, 'verifyTransfer']);
Route::post('/cancel/{transferId}', [TransactionController::class, 'cancelTransfer']);
});




Route::prefix('deposits')->group(function () {
Route::post('/confirm', [DepositController::class, 'confirmDeposit']);
Route::get('/history', [DepositController::class, 'getDepositHistory']);

});


    
});







// Admin Authentication
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::post('/logout', [AdminAuthController::class, 'logout'])->middleware('auth:sanctum');
});

// Protected Admin Routes
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);
    
    // User Management
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users/{id}/verify', [UserController::class, 'verify']);
    Route::post('/users/{id}/suspend', [UserController::class, 'suspend']);
    Route::post('/users/{id}/activate', [UserController::class, 'activate']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::post('/users/{id}/impersonate', [UserController::class, 'impersonate']);
    Route::post('/users/{id}/add-balance', [UserController::class, 'addBalance']);
    Route::post('/users/{id}/send-email', [UserController::class, 'sendEmail']);
    Route::get('/users/{id}/transactions', [UserController::class, 'getUserTransactions']);
    Route::get('/users/{id}/kyc', [UserController::class, 'getUserKyc']);
    
    // KYC Management
    Route::get('/kyc/pending', [AdminKycController::class, 'pending']);
    Route::get('/kyc/history', [AdminKycController::class, 'history']);
    Route::post('/kyc/{id}/approve', [AdminKycController::class, 'approve']);
    Route::post('/kyc/{id}/reject', [AdminKycController::class, 'reject']);
    
    // Transaction Management
    Route::get('/transactions', [AdminTransactionController::class, 'index']);
    Route::get('/transactions/stats', [AdminTransactionController::class, 'stats']);
    Route::post('/transactions/{id}/verify', [AdminTransactionController::class, 'verify']);
    
    // Settings
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings', [SettingsController::class, 'update']);
    Route::post('/settings/email-test', [SettingsController::class, 'testEmail']);
});