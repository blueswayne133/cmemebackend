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
}
