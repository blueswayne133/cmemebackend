<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
     * Get all wallet settings
     */
    public static function getWalletSettings()
    {
        $settings = self::where('category', 'wallet')->get();
        $result = [];

        foreach ($settings as $setting) {
            // Convert value to proper type
            $value = $setting->value;
            
            switch ($setting->type) {
                case 'boolean':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'integer':
                    $value = (int) $value;
                    break;
                case 'double':
                    $value = (float) $value;
                    break;
                default:
                    // string - keep as is
                    break;
            }

            $result[$setting->key] = $value;
        }

        return $result;
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

        // Convert value to proper type
        $value = $setting->value;
        
        switch ($setting->type) {
            case 'boolean':
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                break;
            case 'integer':
                $value = (int) $value;
                break;
            case 'double':
                $value = (float) $value;
                break;
            default:
                // string - keep as is
                break;
        }

        return $value;
    }

    /**
     * Set a specific wallet setting value
     */
    public static function setWalletValue($key, $value)
    {
        $type = gettype($value);
        
        return self::updateOrCreate(
            [
                'category' => 'wallet',
                'key' => $key
            ],
            [
                'value' => $value,
                'type' => $type
            ]
        );
    }

    /**
     * Update multiple wallet settings at once
     */
    public static function updateWalletSettings(array $settings)
    {
        foreach ($settings as $key => $value) {
            self::setWalletValue($key, $value);
        }
        
        // Clear cache
        if (function_exists('cache')) {
            cache()->forget('platform_settings');
        }
        
        return true;
    }



    


    
}