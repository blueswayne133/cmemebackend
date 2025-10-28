<?php

use App\Http\Controllers\Admin\AdminKycController;
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
use App\Http\Controllers\Admin\AdminTransactionController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\PlatformController;
use App\Models\Admin;
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
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-verification', [AuthController::class, 'resendVerificationCode']);
Route::get('/platform-statistics', [PlatformController::class, 'getPlatformStats']);
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
     Route::post('/claim-usdc', [ReferralController::class, 'claimUSDC']); 
    });


    // P2P Trading routes
    Route::prefix('p2p')->group(function () {
    Route::get('/trades', [P2PController::class, 'getTrades']);
    Route::post('/trades', [P2PController::class, 'createTrade']);
    Route::get('/trades/user', [P2PController::class, 'getUserTrades']);
    Route::get('/trades/{tradeId}', [P2PController::class, 'getTradeDetails']);
    Route::post('/trades/{tradeId}/initiate', [P2PController::class, 'initiateTrade']);
    Route::post('/trades/{tradeId}/cancel', [P2PController::class, 'cancelTrade']);
    Route::post('/trades/{tradeId}/upload-proof', [P2PController::class, 'uploadPaymentProof']);
    Route::post('/trades/{tradeId}/confirm-payment', [P2PController::class, 'confirmPayment']);
    Route::post('/trades/{tradeId}/dispute', [P2PController::class, 'createDispute']);
    Route::delete('/trades/{tradeId}', [P2PController::class, 'deleteTrade']);
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





// Public admin routes
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']); // ← Use AdminAuthController
});




// Admin Authentication

Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);
    Route::get('/dashboard/recent-activity', [DashboardController::class, 'getRecentActivity']);

    // Users Management
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users/{id}/verify', [UserController::class, 'verify']);
    Route::post('/users/{id}/suspend', [UserController::class, 'suspend']);
    Route::post('/users/{id}/balance', [UserController::class, 'updateBalance']);
    Route::get('/users/{id}/transactions', [UserController::class, 'getUserTransactions']);
    Route::get('/users/{id}/trades', [UserController::class, 'getUserTrades']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);


 
        // Transaction Management
    Route::get('/transactions', [AdminTransactionController::class, 'index']);
    Route::get('/transactions/{transaction}', [AdminTransactionController::class, 'show']);
    Route::post('/transactions', [AdminTransactionController::class, 'store']);
    Route::put('/transactions/{transaction}', [AdminTransactionController::class, 'update']);
    Route::delete('/transactions/{transaction}', [AdminTransactionController::class, 'destroy']);
    Route::get('/transactions/stats/summary', [AdminTransactionController::class, 'getStats']);
    // P2P Trading
    Route::get('/p2p/trades', [P2PController::class, 'getTrades']);
    Route::get('/p2p/trades/{id}', [P2PController::class, 'getTrade']);
    Route::get('/p2p/disputes', [P2PController::class, 'getDisputes']);
    Route::post('/p2p/disputes/{id}/resolve', [P2PController::class, 'resolveDispute']);
    Route::post('/p2p/trades/{id}/cancel', [P2PController::class, 'cancelTrade']);

    // Wallet Management
    Route::get('/wallets', [WalletController::class, 'index']);
    Route::post('/wallets/{id}/status', [WalletController::class, 'updateWalletStatus']);
    Route::post('/wallets/{id}/bonus', [WalletController::class, 'grantBonus']);

    // Settings
    Route::get('/settings/system', [SettingsController::class, 'getSystemSettings']);
    Route::post('/settings/system', [SettingsController::class, 'updateSystemSettings']);
    Route::get('/settings/tasks', [SettingsController::class, 'getTaskSettings']);
    Route::post('/settings/tasks', [SettingsController::class, 'createTask']);
    Route::put('/settings/tasks/{id}', [SettingsController::class, 'updateTask']);
    Route::get('/settings/admins', [SettingsController::class, 'getAdminUsers']);
    Route::post('/settings/admins', [SettingsController::class, 'createAdmin']);
    Route::put('/settings/admins/{id}', [SettingsController::class, 'updateAdmin']);
});



// Admin KYC Management Routes
Route::prefix('admin/kyc')->group(function () {
    Route::get('/', [AdminKycController::class, 'index']);
    Route::get('/pending', [AdminKycController::class, 'pending']);
    Route::get('/{id}', [AdminKycController::class, 'show']);
    Route::post('/{id}/approve', [AdminKycController::class, 'approve']);
    Route::post('/{id}/reject', [AdminKycController::class, 'reject']);
    Route::delete('/{id}', [AdminKycController::class, 'destroy']); // Add delete route
    Route::get('/user/{userId}/history', [AdminKycController::class, 'getUserKycHistory']);
    Route::get('/{id}/document/{type}', [AdminKycController::class, 'getDocument']);
});



// Add these routes to your admin section in api.php

Route::prefix('admin')->group(function () {
    // ... existing routes ...
    
    // Task Management Routes
    Route::prefix('tasks')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\AdminTaskController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Admin\AdminTaskController::class, 'create']);
        Route::get('/{taskId}', [\App\Http\Controllers\Admin\AdminTaskController::class, 'show']);
        Route::put('/{taskId}', [\App\Http\Controllers\Admin\AdminTaskController::class, 'update']);
        Route::delete('/{taskId}', [\App\Http\Controllers\Admin\AdminTaskController::class, 'delete']);
        Route::post('/{taskId}/toggle-status', [\App\Http\Controllers\Admin\AdminTaskController::class, 'toggleStatus']);
        Route::get('/users/{userId}', [\App\Http\Controllers\Admin\AdminTaskController::class, 'getUserTasks']);
        Route::post('/{taskId}/reset', [\App\Http\Controllers\Admin\AdminTaskController::class, 'resetUserTask']);
        Route::post('/{taskId}/complete', [\App\Http\Controllers\Admin\AdminTaskController::class, 'forceCompleteTask']);
        Route::get('/stats', [\App\Http\Controllers\Admin\AdminTaskController::class, 'getTaskStats']);
    });
});