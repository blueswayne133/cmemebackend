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
    
    return response()->json([
        'status' => 'success',
        'message' => 'Profile retrieved successfully',
        'data' => [
            'user' => $user,
        ]
    ]);
}



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


}
