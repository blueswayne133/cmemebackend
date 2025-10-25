<?php

namespace App\Services;

use App\Models\User;
use App\Models\TwoFactorCode;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    public function generateAuthenticatorSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function verifyAuthenticatorCode(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
    }

    public function getAuthenticatorQRCodeUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );
    }

    public function generateEmailCode(User $user, string $action = 'login'): TwoFactorCode
    {
        $code = $this->generateNumericCode(6);
        
        $twoFactorCode = TwoFactorCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'type' => 'email',
            'action' => $action,
            'expires_at' => now()->addMinutes(10),
        ]);

        // Send email with code
        Mail::send('emails.two-factor-code', [
            'code' => $code,
            'user' => $user,
            'action' => $action,
        ], function ($message) use ($user) {
            $message->to($user->email)
                   ->subject('Your Two-Factor Authentication Code');
        });

        return $twoFactorCode;
    }

    public function generateSMSCode(User $user, string $action = 'login'): TwoFactorCode
    {
        $code = $this->generateNumericCode(6);
        
        $twoFactorCode = TwoFactorCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'type' => 'sms',
            'action' => $action,
            'expires_at' => now()->addMinutes(5),
        ]);

        // Here you would integrate with an SMS service like Twilio
        // This is a placeholder for SMS integration
        // SMS::send($user->phone, "Your verification code is: {$code}");

        return $twoFactorCode;
    }

    public function generateBackupCode(): string
    {
        return strtoupper(Str::random(8));
    }

    public function verifyCode(User $user, string $code, string $type, string $action = 'login'): bool
    {
        $twoFactorCode = TwoFactorCode::where('user_id', $user->id)
            ->valid()
            ->ofType($type)
            ->forAction($action)
            ->where('code', $code)
            ->first();

        if ($twoFactorCode) {
            $twoFactorCode->markAsUsed();
            return true;
        }

        // Check backup codes
        if ($type === 'email' || $type === 'sms') {
            return $user->useBackupCode($code);
        }

        return false;
    }

    protected function generateNumericCode(int $length): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }
        return $code;
    }

    public function cleanupExpiredCodes(): void
    {
        TwoFactorCode::where('expires_at', '<', now())->delete();
    }
}