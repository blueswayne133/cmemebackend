<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSocialHandle extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'platform',
        'handle',
        'connected_at',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
    ];

    /**
     * Relationship with User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for specific platform
     */
    public function scopePlatform($query, $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Get platform display name
     */
    public function getPlatformNameAttribute(): string
    {
        return match($this->platform) {
            'twitter' => 'Twitter',
            'telegram' => 'Telegram',
            default => ucfirst($this->platform),
        };
    }
}