<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
       

        $validator = Validator::make($request->all(), [
        'username' => 'required|string|min:3|max:255|unique:users',
        'email' => 'required|string|email|max:255|unique:users',
        'password' => 'required|string|min:6', // removed confirmed
        'firstname' => 'required|string|min:2|max:255',
        'lastname' => 'required|string|min:2|max:255',
        // 'referral_code' => 'sometimes|string|exists:users,referral_code',
    ]);

    // Add manual password confirmation check
    if ($request->password !== $request->password_confirmation) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed. Please check your input.',
            'errors' => ['password' => ['The password confirmation does not match.']]
        ], 422);
    }

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed. Please check your input.',
                'errors' => $validator->errors()
            ], 422);
        }

        $referredBy = null;
        if ($request->referral_code) {
            $referredBy = User::where('referral_code', $request->referral_code)->first();
        }

        $user = User::create([
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'first_name' => $request->firstname,
            'last_name' => $request->lastname,
            'uid' => 'UID' . str_pad(random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'referral_code' => Str::random(8),
            'referred_by' => $referredBy?->id,
            'wallet_address' => '0x' . Str::random(40),
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Registration successful!',
            'data' => [
                'user' => $user,
                'token' => $token,
            ]
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->login)
            ->orWhere('username', $request->login)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials. Please try again.',
            ], 401);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful!',
            'data' => [
                'user' => $user,
                'token' => $token,
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully'
        ]);
    }

    public function user(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'User retrieved successfully',
            'data' => [
                'user' => $request->user(),
            ]
        ]);
    }
}