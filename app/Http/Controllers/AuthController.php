<?php

namespace App\Http\Controllers;

use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected $twoFactorService;

    public function __construct(TwoFactorService $twoFactorService)
    {
        $this->twoFactorService = $twoFactorService;
    }

   public function register(Request $request)
{
    $validator = Validator::make($request->all(), [
        'username' => 'required|string|min:3|max:255|unique:users',
        'email' => 'required|string|email|max:255|unique:users',
        'password' => 'required|string|min:6',
        'firstname' => 'required|string|min:2|max:255',
        'lastname' => 'required|string|min:2|max:255',
    ]);

    if ($request->password !== $request->password_confirmation) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed. Please check your input.',
            'errors' => ['password' => ['The password confirmation does not match.']]
        ], 422);
    }

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed. Please check your input.',
            'errors' => $validator->errors()
        ], 422);
    }

    $referredBy = null;
    if ($request->referral_code) {
        $referredBy = User::where('referral_code', $request->referral_code)->first();
        
        // if ($referredBy) {
        //     DB::transaction(function () use ($referredBy) {
        //         $referredBy->increment('referral_usdc_balance', 0.1);
        //         $referredBy->increment('token_balance', 0.5);
        //         $referredBy->increment('referral_token_balance', 0.5);
        //     });
        // }
    }

    $user = User::create([
        'username' => $request->username,
        'email' => $request->email,
        'password' => Hash::make($request->password),
        'first_name' => $request->firstname,
        'last_name' => $request->lastname,
        'uid' => str_pad(random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
        'referral_code' => $this->generateUniqueReferralCode(),
        'referred_by' => $referredBy?->id,
        'wallet_address' => '0x' . Str::random(40),
        'referral_usdc_balance' => 0,
        'referral_token_balance' => 0,
        'kyc_status' => 'not_submitted',
        'is_verified' => false,
        'token_balance' => 0,
        'usdc_balance' => 0,
        'mining_streak' => 0,
        'email_verification_code' => str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'email_verified_at' => null,
    ]);

    // Send email verification code
    try {
        Mail::to($user->email)->send(new EmailVerificationMail($user->email_verification_code));
    } catch (\Exception $e) {
        Log::error('Failed to send verification email: ' . $e->getMessage());
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Registration successful! Please check your email for verification code.',
        'data' => [
            'user_id' => $user->id,
            'email' => $user->email,
            'requires_verification' => true
        ]
    ], 201);
}


/**
 * Generate a unique referral code
 */
private function generateUniqueReferralCode()
{
    $maxAttempts = 10;
    $attempts = 0;

    do {
        // Generate a more unique code with more characters
        $code = 'cmeme' . Str::random(4); // Increased from 2 to 4 characters
        $attempts++;
        
        if ($attempts > $maxAttempts) {
            // If we can't find a unique code with the pattern, generate completely random
            $code = Str::lower(Str::random(8));
        }
    } while (User::where('referral_code', $code)->exists());

    return $code;
}

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->login)
            ->orWhere('username', $request->login)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials. Please try again.',
            ], 401);
        }

        // Check if 2FA is enabled
        if ($user->hasTwoFactorEnabled()) {
            $methods = $user->getTwoFactorMethods();
            
            // Send codes based on enabled methods
            if (in_array('email', $methods)) {
                $this->twoFactorService->generateEmailCode($user, 'login');
            }
            
            if (in_array('sms', $methods) && $user->hasPhoneVerified()) {
                $this->twoFactorService->generateSMSCode($user, 'login');
            }

            return response()->json([
                'status' => '2fa_required',
                'message' => 'Two-factor authentication required',
                'data' => [
                    'login' => $request->login,
                    'available_methods' => $methods,
                    'user_id' => $user->id,
                ]
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful!',
            'data' => [
                'user' => $user,
                'token' => $token,
            ]
        ]);
    }


    public function verifyEmail(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email' => 'required|email|exists:users,email',
        'code' => 'required|string|size:6',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    $user = User::where('email', $request->email)->first();

    if (!$user) {
        return response()->json([
            'status' => 'error',
            'message' => 'User not found',
        ], 404);
    }

    if ($user->email_verified_at) {
        return response()->json([
            'status' => 'error',
            'message' => 'Email already verified',
        ], 422);
    }

    if ($user->email_verification_code !== $request->code) {
        return response()->json([
            'status' => 'error',
            'message' => 'Invalid verification code',
        ], 422);
    }

    // Mark email as verified
    $user->update([
        'email_verified_at' => now(),
        'is_verified' => true,
        'email_verification_code' => null
    ]);

    $token = $user->createToken('auth-token')->plainTextToken;

    return response()->json([
        'status' => 'success',
        'message' => 'Email verified successfully!',
        'data' => [
            'user' => $user,
            'token' => $token,
        ]
    ]);
}

// ADD resendVerificationCode METHOD HERE - after verifyEmail
public function resendVerificationCode(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email' => 'required|email|exists:users,email',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    $user = User::where('email', $request->email)->first();

    if (!$user) {
        return response()->json([
            'status' => 'error',
            'message' => 'User not found',
        ], 404);
    }

    if ($user->email_verified_at) {
        return response()->json([
            'status' => 'error',
            'message' => 'Email already verified',
        ], 422);
    }

    // Generate new code
    $user->update([
        'email_verification_code' => str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT)
    ]);

    // Resend email
    try {
        Mail::to($user->email)->send(new EmailVerificationMail($user->email_verification_code));
    } catch (\Exception $e) {
        Log::error('Failed to resend verification email: ' . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to send verification email. Please try again.',
        ], 500);
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Verification code sent successfully',
    ]);
}

    public function verifyTwoFactor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login' => 'required|string',
            'code' => 'required|string',
            'type' => 'required|string|in:email,sms,authenticator,backup',
            'user_id' => 'sometimes|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find user by login or user_id
        if ($request->has('user_id')) {
            $user = User::find($request->user_id);
        } else {
            $user = User::where('email', $request->login)
                ->orWhere('username', $request->login)
                ->first();
        }

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        // Verify the 2FA code
        $isValid = false;
        
        if ($request->type === 'authenticator') {
            // Verify authenticator code
            if ($user->two_factor_secret) {
                $isValid = $this->twoFactorService->verifyAuthenticatorCode($user->two_factor_secret, $request->code);
            }
        } else {
            // Verify email, SMS, or backup code
            $isValid = $this->twoFactorService->verifyCode($user, $request->code, $request->type, 'login');
        }

        if (!$isValid) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired verification code',
            ], 422);
        }

        // Check if this is a backup code to track usage
        if ($request->type === 'backup') {
            // Backup code usage is already handled in verifyCode method
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful!',
            'data' => [
                'user' => $user,
                'token' => $token,
            ]
        ]);
    }

    public function resendTwoFactorCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login' => 'required|string',
            'type' => 'required|string|in:email,sms',
            'user_id' => 'sometimes|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find user by login or user_id
        if ($request->has('user_id')) {
            $user = User::find($request->user_id);
        } else {
            $user = User::where('email', $request->login)
                ->orWhere('username', $request->login)
                ->first();
        }

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        // Check if the requested method is enabled for the user
        $enabledMethods = $user->getTwoFactorMethods();
        if (!in_array($request->type, $enabledMethods)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This two-factor method is not enabled for your account',
            ], 422);
        }

        // For SMS, check if phone is verified
        if ($request->type === 'sms' && !$user->hasPhoneVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Phone number not verified',
            ], 422);
        }

        // Resend the code
        try {
            if ($request->type === 'email') {
                $this->twoFactorService->generateEmailCode($user, 'login');
            } elseif ($request->type === 'sms') {
                $this->twoFactorService->generateSMSCode($user, 'login');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Verification code sent successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send verification code. Please try again.',
            ], 500);
        }
    }

    public function getTwoFactorMethods(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->login)
            ->orWhere('username', $request->login)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $methods = $user->getTwoFactorMethods();
        $hasBackupCodes = $user->hasBackupCodes();

        return response()->json([
            'status' => 'success',
            'data' => [
                'available_methods' => $methods,
                'has_backup_codes' => $hasBackupCodes,
                'user_id' => $user->id,
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully'
        ]);
    }

    // public function user(Request $request)
    // {
    //     $user = $request->user()->load('securitySettings');
        
    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'User retrieved successfully',
    //         'data' => [
    //             'user' => $user,
    //         ]
    //     ]);  
    // }

    public function user(Request $request)
   {
        $user = $request->user()->load('securitySettings');
    
    // Get current CMEME rate from settings
    $cmemeRate = Setting::getCmemRate(0.2);
    
    // Add rate to user data for frontend
    $userData = $user->toArray();
    $userData['cmeme_rate'] = $cmemeRate;
    
    return response()->json([
        'status' => 'success',
        'message' => 'Profile retrieved successfully',
        'data' => [
            'user' => $userData, // Now includes cmeme_rate
            'cmeme_rate' => $cmemeRate
        ]
    ]);
  }

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        // Generate reset token and send email
        $token = Str::random(60);
        
        // Store token in password_resets table (you'll need this migration)
        DB::table('password_resets')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        // Send password reset email
        Mail::to($user->email)->send(new PasswordResetMail($token));

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset link sent to your email',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verify token
        $resetRecord = DB::table('password_resets')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired reset token',
            ], 422);
        }

        // Check if token is expired (1 hour)
        if (now()->diffInMinutes($resetRecord->created_at) > 60) {
            DB::table('password_resets')->where('email', $request->email)->delete();
            return response()->json([
                'status' => 'error',
                'message' => 'Reset token has expired',
            ], 422);
        }

        // Update password
        $user = User::where('email', $request->email)->first();
        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Delete used token
        DB::table('password_resets')->where('email', $request->email)->delete();

        // Invalidate all existing tokens
        $user->tokens()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset successfully',
        ]);
    }

    public function checkAuth(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Not authenticated',
            ], 401);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Authenticated',
            'data' => [
                'user' => $user,
            ]
        ]);
    }


    public function getPlatformStats()
 {
    try {
        // Get total active users (users who have logged in recently)
        $activeUsers = User::where('last_login_at', '>=', now()->subDays(30))->count();
        
        // Get total mined tokens (sum of all token balances)
        $totalMined = User::sum('token_balance');
        
        // Get total USDC distributed
        $totalUSDC = User::sum('usdc_balance');
        
        // Get platform uptime (you might want to calculate this differently)
        $uptime = 99.9; // This could be calculated based on your monitoring

        return response()->json([
            'status' => 'success',
            'data' => [
                'active_miners' => $activeUsers,
                'total_mined' => round($totalMined, 2),
                'total_usdc' => round($totalUSDC, 2),
                'uptime' => $uptime
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to fetch platform statistics'
        ], 500);
    }
}
}