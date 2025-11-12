<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProfileController extends Controller
{


    public function getProfile(Request $request)
  {
    $user = $request->user();
    
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
    // public function getProfile(Request $request)
    // {
    //     $user = $request->user();
        
    //     // Get current CMEME rate from settings
    //     $cmemeRate = Setting::getCmemRate(0.2);
        
    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Profile retrieved successfully',
    //         'data' => [
    //             'user' => $user,
    //             'cmeme_rate' => $cmemeRate
    //         ]
    //     ]);
    // }

    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar_url' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $user->avatar_url = $request->avatar_url;
        $user->save();

        return response()->json([
            'success' => true,
            'avatar_url' => $user->avatar_url
        ]);
    }

    public function getDepositWallet(Request $request)
    {
        try {
            $settings = Cache::remember('platform_settings', 3600, function () {
                return [
                    'wallet' => Setting::getWalletSettings(),
                    'token' => Setting::getTokenSettings() // Add token settings with CMEME rate
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
}