<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    /**
     * Admin login
     */
    public function login(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Find admin by email
            $admin = Admin::where('email', $request->email)->first();

            // Check if admin exists, is active, and password is correct
            if (!$admin || !Hash::check($request->password, $admin->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            // Check if admin is active
            // if (!$admin->is_active) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Your account has been deactivated. Please contact system administrator.'
            //     ], 403);
            // }

            // Update last login timestamp
            $admin->update([
                'last_login_at' => now()
            ]);

            // Create token
            $token = $admin->createToken('admin-token', ['admin'])->plainTextToken;

            // Return success response
            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'admin' => [
                        'id' => $admin->id,
                        'name' => $admin->name,
                        'email' => $admin->email,
                        'role' => $admin->role,
                        'permissions' => $admin->permissions,
                        'is_active' => $admin->is_active,
                        'last_login_at' => $admin->last_login_at,
                    ],
                    'token' => $token
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Login failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Admin logout
     */
    public function logout(Request $request)
    {
        try {
            // Revoke current access token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed'
            ], 500);
        }
    }

    /**
     * Get authenticated admin
     */
    public function user(Request $request)
    {
        $admin = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                    'permissions' => $admin->permissions,
                    'is_active' => $admin->is_active,
                    'last_login_at' => $admin->last_login_at,
                    'email_verified_at' => $admin->email_verified_at,
                ]
            ]
        ], 200);
    }

    /**
     * Refresh token (optional)
     */
    public function refresh(Request $request)
    {
        $admin = $request->user();
        
        // Revoke current token
        $request->user()->currentAccessToken()->delete();
        
        // Create new token
        $token = $admin->createToken('admin-token', ['admin'])->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token
            ]
        ], 200);
    }
}