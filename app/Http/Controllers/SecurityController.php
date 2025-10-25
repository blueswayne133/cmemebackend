<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class SecurityController extends Controller
{
    protected TwoFactorService $twoFactorService;

    public function __construct(TwoFactorService $twoFactorService)
    {
        $this->twoFactorService = $twoFactorService;
    }

    public function getSecuritySettings(Request $request)
    {
        $user = $request->user();
        $user->load('securitySettings');

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => [
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'phone_verified' => $user->phone_verified,
                    'two_factor_enabled' => $user->two_factor_enabled,
                    'two_factor_type' => $user->two_factor_type,
                    'has_backup_codes' => $user->hasBackupCodes(),
                ],
                'security_settings' => $user->securitySettings,
                'backup_codes' => $user->backup_codes, // Only return if needed for display
            ]
        ]);
    }

    public function updatePhone(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|unique:users,phone,' . $request->user()->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $user->update([
            'phone' => $request->phone,
            'phone_verified' => false,
        ]);

        // Send verification code
        $this->twoFactorService->generateSMSCode($user, 'verify_phone');

        return response()->json([
            'status' => 'success',
            'message' => 'Phone number updated. Verification code sent.',
        ]);
    }

    public function verifyPhone(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid code format',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!$this->twoFactorService->verifyCode($user, $request->code, 'sms', 'verify_phone')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired verification code',
            ], 422);
        }

        $user->update(['phone_verified' => true]);

        return response()->json([
            'status' => 'success',
            'message' => 'Phone number verified successfully',
        ]);
    }

    public function setupAuthenticator(Request $request)
    {
        $user = $request->user();
        
        $secret = $this->twoFactorService->generateAuthenticatorSecret();
        $qrCodeUrl = $this->twoFactorService->getAuthenticatorQRCodeUrl($user, $secret);

        // Store temporarily in session or return for verification
        return response()->json([
            'status' => 'success',
            'data' => [
                'secret' => $secret,
                'qr_code_url' => $qrCodeUrl,
            ]
        ]);
    }

    public function verifyAuthenticator(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
            'secret' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid code',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!$this->twoFactorService->verifyAuthenticatorCode($request->secret, $request->code)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid authenticator code',
            ], 422);
        }

        // Enable authenticator 2FA
        $user->update([
            'two_factor_secret' => $request->secret,
        ]);

        $user->securitySettings()->update([
            'authenticator_2fa_enabled' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Authenticator setup successfully',
        ]);
    }

    public function enableEmail2FA(Request $request)
    {
        $user = $request->user();

        // Send verification code
        $this->twoFactorService->generateEmailCode($user, 'change_security');

        return response()->json([
            'status' => 'success',
            'message' => 'Verification code sent to your email',
        ]);
    }

    public function verifyEmail2FA(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid code format',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!$this->twoFactorService->verifyCode($user, $request->code, 'email', 'change_security')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired verification code',
            ], 422);
        }

        $user->securitySettings()->update([
            'email_2fa_enabled' => true,
        ]);

        $this->updateTwoFactorStatus($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Email two-factor authentication enabled',
        ]);
    }

    public function enableSMS2FA(Request $request)
    {
        $user = $request->user();

        if (!$user->hasPhoneVerified()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please verify your phone number first',
            ], 422);
        }

        // Send verification code
        $this->twoFactorService->generateSMSCode($user, 'change_security');

        return response()->json([
            'status' => 'success',
            'message' => 'Verification code sent to your phone',
        ]);
    }

    public function verifySMS2FA(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid code format',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!$this->twoFactorService->verifyCode($user, $request->code, 'sms', 'change_security')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired verification code',
            ], 422);
        }

        $user->securitySettings()->update([
            'sms_2fa_enabled' => true,
        ]);

        $this->updateTwoFactorStatus($user);

        return response()->json([
            'status' => 'success',
            'message' => 'SMS two-factor authentication enabled',
        ]);
    }

    public function disable2FA(Request $request)
    {
        $user = $request->user();
        $type = $request->input('type'); // 'email', 'sms', 'authenticator'

        $updateData = [];
        switch ($type) {
            case 'email':
                $updateData['email_2fa_enabled'] = false;
                break;
            case 'sms':
                $updateData['sms_2fa_enabled'] = false;
                break;
            case 'authenticator':
                $updateData['authenticator_2fa_enabled'] = false;
                $user->update(['two_factor_secret' => null]);
                break;
        }

        $user->securitySettings()->update($updateData);
        $this->updateTwoFactorStatus($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Two-factor authentication disabled',
        ]);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Current password is incorrect',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        $user->securitySettings()->update([
            'last_password_change' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Password changed successfully',
        ]);
    }

    public function generateBackupCodes(Request $request)
    {
        $user = $request->user();
        $backupCodes = $user->generateBackupCodes();

        return response()->json([
            'status' => 'success',
            'data' => [
                'backup_codes' => $backupCodes,
            ],
            'message' => 'Backup codes generated. Please save them in a secure place.',
        ]);
    }

    protected function updateTwoFactorStatus(User $user)
    {
        $securitySettings = $user->securitySettings;
        
        $has2FA = $securitySettings->hasAny2FAEnabled();
        
        $user->update([
            'two_factor_enabled' => $has2FA,
            'two_factor_enabled_at' => $has2FA ? now() : null,
        ]);
    }
}