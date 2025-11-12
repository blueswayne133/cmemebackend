<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\TokenRateHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    public function getSettings()
    {
        try {
            $settings = [
                'wallet' => Setting::getWalletSettings(),
                'token' => Setting::getTokenSettings()
            ];

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
                'token.cmeme_rate' => 'required|numeric|min:0' // Keep it simple - allow 0
            ]);

            // Update ALL wallet settings (including cmeme_rate)
            Setting::updateWalletSettings($validated['wallet']);
            
            // Also update cmeme_rate from token section to be sure
            if (isset($validated['token']['cmeme_rate'])) {
                $previousRate = Setting::getCmemRate();
                $newRate = $validated['token']['cmeme_rate'];
                
                Setting::setCmemRate($newRate);
                
                
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Settings saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to save settings: ' . $e->getMessage()
            ], 500);
        }
    }

    
}