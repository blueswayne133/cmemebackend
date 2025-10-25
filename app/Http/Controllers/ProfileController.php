<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

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
}
