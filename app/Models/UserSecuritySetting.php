<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSecuritySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email_2fa_enabled',
        'sms_2fa_enabled',
        'authenticator_2fa_enabled',
        'login_alerts',
        'new_device_alerts',
        'last_password_change',
        'trusted_devices',
    ];

    protected $casts = [
        'email_2fa_enabled' => 'boolean',
        'sms_2fa_enabled' => 'boolean',
        'authenticator_2fa_enabled' => 'boolean',
        'login_alerts' => 'boolean',
        'new_device_alerts' => 'boolean',
        'last_password_change' => 'datetime',
        'trusted_devices' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function hasAny2FAEnabled(): bool
    {
        return $this->email_2fa_enabled || $this->sms_2fa_enabled || $this->authenticator_2fa_enabled;
    }

    public function getEnabled2FAMethods(): array
    {
        $methods = [];
        if ($this->authenticator_2fa_enabled) $methods[] = 'authenticator';
        if ($this->email_2fa_enabled) $methods[] = 'email';
        if ($this->sms_2fa_enabled) $methods[] = 'sms';
        return $methods;
    }
}