<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'category', 
        'key', 
        'value', 
        'type'
    ];

    /**
     * Get all wallet settings (including cmeme_rate)
     */
    public static function getWalletSettings()
    {
        $settings = self::where('category', 'wallet')->get();
        $result = [];

        foreach ($settings as $setting) {
            $value = self::convertValue($setting->value, $setting->type);
            $result[$setting->key] = $value;
        }

        // Set defaults for all wallet settings including cmeme_rate
        $defaults = [
            'deposit_address' => '',
            'network' => 'base',
            'token' => 'USDC',
            'min_deposit' => 10,
            'cmeme_rate' => 0.2  // Keep it here for now
        ];

        foreach ($defaults as $key => $defaultValue) {
            if (!isset($result[$key])) {
                $result[$key] = $defaultValue;
            }
        }

        return $result;
    }

    /**
     * Get CMEME token rate - SIMPLE VERSION
     */
    public static function getCmemRate($default = 0.2)
    {
        return self::getWalletValue('cmeme_rate', $default);
    }

    /**
     * Set CMEME token rate - SIMPLE VERSION
     */
    public static function setCmemRate($rate)
    {
        return self::setWalletValue('cmeme_rate', (float)$rate);
    }

    /**
     * Get token settings (just cmeme_rate for now)
     */
    public static function getTokenSettings()
    {
        return [
            'cmeme_rate' => self::getCmemRate()
        ];
    }

    /**
     * Get a specific wallet setting value
     */
    public static function getWalletValue($key, $default = null)
    {
        $setting = self::where('category', 'wallet')
                      ->where('key', $key)
                      ->first();

        if (!$setting) {
            return $default;
        }

        return self::convertValue($setting->value, $setting->type);
    }

    /**
     * Set a specific wallet setting value
     */
    public static function setWalletValue($key, $value)
    {
        $type = gettype($value);
        
        $result = self::updateOrCreate(
            [
                'category' => 'wallet',
                'key' => $key
            ],
            [
                'value' => $value,
                'type' => $type
            ]
        );
        
        // Clear cache
        Cache::forget('platform_settings');
        
        return $result;
    }

    /**
     * Update multiple wallet settings at once
     */
    public static function updateWalletSettings(array $settings)
    {
        foreach ($settings as $key => $value) {
            self::setWalletValue($key, $value);
        }
        
        Cache::forget('platform_settings');
        
        return true;
    }

    /**
     * Helper method to convert value to proper type
     */
    private static function convertValue($value, $type)
    {
        switch ($type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'integer':
                return (int) $value;
            case 'double':
                return (float) $value;
            default:
                return $value;
        }
    }
}