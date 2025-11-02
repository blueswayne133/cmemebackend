<?php
// app/Http/Controllers/Admin/AdminController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function index()
    {
        $admins = Admin::all();

        return response()->json([
            'status' => 'success',
            'data' => $admins
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:admins,email',
            'password' => 'required|string|min:8',
            'is_super_admin' => 'boolean',
            'permissions' => 'array',
            'status' => 'boolean'
        ]);

        $admin = Admin::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Admin created successfully',
            'data' => $admin
        ]);
    }

    public function update(Request $request, $id)
    {
        $admin = Admin::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('admins')->ignore($admin->id)
            ],
            'password' => 'sometimes|string|min:8|nullable',
            'is_super_admin' => 'boolean',
            'permissions' => 'array',
            'status' => 'boolean'
        ]);

        // Remove password if empty
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $admin->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Admin updated successfully',
            'data' => $admin
        ]);
    }

    public function destroy($id)
    {
        $admin = Admin::findOrFail($id);

        // Prevent deleting super admin or self
        if ($admin->is_super_admin) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete super admin'
            ], 422);
        }

        if ($admin->id === auth('admin')->id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete your own account'
            ], 422);
        }

        $admin->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Admin deleted successfully'
        ]);
    }

    public function toggleStatus($id)
    {
        $admin = Admin::findOrFail($id);

        // Prevent deactivating self
        if ($admin->id === auth('admin')->id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot deactivate your own account'
            ], 422);
        }

        $admin->update([
            'status' => !$admin->status
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Admin status updated successfully',
            'data' => $admin
        ]);
    }

    public function changePassword(Request $request)
    {
        $admin = auth('admin')->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        // Check current password
        if (!Hash::check($validated['current_password'], $admin->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Current password is incorrect'
            ], 422);
        }

        $admin->update([
            'password' => $validated['new_password']
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Password changed successfully'
        ]);
    }
}