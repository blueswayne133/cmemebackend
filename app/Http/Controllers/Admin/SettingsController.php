<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class SettingsController extends Controller
{
    public function getSystemSettings()
    {
        $settings = [
            'mining_reward' => config('app.mining_reward', 10),
            'referral_reward' => config('app.referral_reward', 5),
            'kyc_required' => config('app.kyc_required', true),
            'p2p_trading_enabled' => config('app.p2p_trading_enabled', true),
            'wallet_bonus_amount' => config('app.wallet_bonus_amount', 50),
        ];

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    public function updateSystemSettings(Request $request)
    {
        $validated = $request->validate([
            'mining_reward' => 'required|numeric|min:0',
            'referral_reward' => 'required|numeric|min:0',
            'kyc_required' => 'required|boolean',
            'p2p_trading_enabled' => 'required|boolean',
            'wallet_bonus_amount' => 'required|numeric|min:0',
        ]);

        // In a real application, you would save these to a settings table or config file
        // For now, we'll just return the validated data

        return response()->json([
            'success' => true,
            'message' => 'System settings updated successfully',
            'data' => $validated
        ]);
    }

    public function getTaskSettings()
    {
        $tasks = Task::withCount(['taskProgress as completions_today' => function($query) {
            $query->whereDate('completion_date', today());
        }])->get();

        return response()->json([
            'success' => true,
            'data' => $tasks
        ]);
    }

    public function updateTask(Request $request, $id)
    {
        $task = Task::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'reward_amount' => 'required|numeric|min:0',
            'reward_type' => 'required|string',
            'max_attempts_per_day' => 'required|integer|min:1',
            'cooldown_minutes' => 'required|integer|min:0',
            'sort_order' => 'required|integer',
            'is_active' => 'required|boolean',
            'is_available' => 'required|boolean',
        ]);

        $task->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Task updated successfully',
            'data' => $task
        ]);
    }

    public function createTask(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'reward_amount' => 'required|numeric|min:0',
            'reward_type' => 'required|string',
            'type' => 'required|string',
            'max_attempts_per_day' => 'required|integer|min:1',
            'cooldown_minutes' => 'required|integer|min:0',
            'sort_order' => 'required|integer',
            'is_active' => 'required|boolean',
            'is_available' => 'required|boolean',
            'metadata' => 'nullable|array',
        ]);

        $task = Task::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Task created successfully',
            'data' => $task
        ]);
    }

    public function getAdminUsers()
    {
        $admins = Admin::where('id', '!=', auth()->id())->get();

        return response()->json([
            'success' => true,
            'data' => $admins
        ]);
    }

    public function createAdmin(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:admins',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'required|string|in:super_admin,admin,moderator',
            'permissions' => 'nullable|array',
        ]);

        $admin = Admin::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'permissions' => $validated['permissions'] ?? [],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Admin user created successfully',
            'data' => $admin
        ]);
    }

    public function updateAdmin(Request $request, $id)
    {
        $admin = Admin::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:admins,email,' . $admin->id,
            'role' => 'required|string|in:super_admin,admin,moderator',
            'permissions' => 'nullable|array',
            'is_active' => 'required|boolean',
        ]);

        $admin->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Admin user updated successfully',
            'data' => $admin
        ]);
    }
}