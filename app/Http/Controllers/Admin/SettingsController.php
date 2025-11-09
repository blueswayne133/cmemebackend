<?php
// app/Http/Controllers/Admin/SettingsController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    public function getSettings()
    {
        try {
            $settings = Cache::remember('platform_settings', 3600, function () {
                return [
                    'wallet' => Setting::getWalletSettings()
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch settings'
            ], 500);
        }
    }

    public function saveSettings(Request $request)
    {
        try {
            $validated = $request->validate([
                'wallet.deposit_address' => 'required|string|max:255',
                'wallet.network' => 'required|string|max:50',
                'wallet.token' => 'required|string|max:10',
                'wallet.min_deposit' => 'required|numeric|min:0',
            ]);

            // Update wallet settings
            Setting::updateWalletSettings($validated['wallet']);

            // Clear cache
            Cache::forget('platform_settings');

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet settings saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to save settings: ' . $e->getMessage()
            ], 500);
        }
    }


    // In your AdminController or SettingsController
public function updateTokenRate(Request $request)
{
    $validated = $request->validate([
        'rate' => 'required|numeric|min:0.0001',
        'reason' => 'nullable|string|max:255'
    ]);

    $previousRate = Setting::getCmemRate();
    $newRate = $validated['rate'];

    // Update the rate
    Setting::setCmemRate($newRate);


    return response()->json([
        'status' => 'success',
        'message' => 'Token rate updated successfully',
        'data' => [
            'previous_rate' => $previousRate,
            'new_rate' => $newRate,
            'change_percentage' => (($newRate - $previousRate) / $previousRate) * 100
        ]
    ]);
}
}