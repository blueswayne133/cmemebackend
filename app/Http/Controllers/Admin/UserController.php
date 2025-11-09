<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Transaction;
use App\Models\KycVerification;
use App\Models\WalletDetail;
use App\Models\UserTaskProgress;
use App\Models\P2PTrade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = User::with(['currentKyc'])
                ->orderBy('created_at', 'desc');

            // Search functionality
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('uid', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->has('status') && $request->status !== 'all') {
                switch ($request->status) {
                    case 'verified':
                        $query->where('is_verified', true);
                        break;
                    case 'unverified':
                        $query->where('is_verified', false);
                        break;
                    case 'kyc_pending':
                        $query->where('kyc_status', 'pending');
                        break;
                    case 'kyc_verified':
                        $query->where('kyc_status', 'verified');
                        break;
                    case '2fa_enabled':
                        $query->where('two_factor_enabled', true);
                        break;
                    case 'phone_verified':
                        $query->where('phone_verified', true);
                        break;
                }
            }

            // Paginate results
            $perPage = $request->get('per_page', 20);
            $users = $query->paginate($perPage);

            // Get stats
            $stats = [
                'total' => User::count(),
                'verified' => User::where('is_verified', true)->count(),
                'kyc_verified' => User::where('kyc_status', 'verified')->count(),
                'new_today' => User::whereDate('created_at', today())->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'users' => $users->items(),
                    'pagination' => [
                        'current_page' => $users->currentPage(),
                        'last_page' => $users->lastPage(),
                        'per_page' => $users->perPage(),
                        'total' => $users->total(),
                    ],
                    'stats' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching users: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = User::with([
                'kycVerifications',
                'walletDetail',
                'securitySettings',
                'referrals',
                'referrer',
                'currentKyc'
            ])->findOrFail($id);

            // Get user statistics
            $stats = [
                'total_earnings' => Transaction::where('user_id', $id)
                    ->whereIn('type', ['earning', 'mining', 'referral'])
                    ->sum('amount'),
                'total_transactions' => Transaction::where('user_id', $id)->count(),
                'completed_trades' => P2PTrade::where(function($query) use ($id) {
                    $query->where('seller_id', $id)->orWhere('buyer_id', $id);
                })->where('status', 'completed')->count(),
                'tasks_completed' => UserTaskProgress::where('user_id', $id)->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'stats' => $stats
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('UserController show error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'User not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'username' => 'sometimes|string|max:255|unique:users,username,' . $id,
                'email' => 'sometimes|email|max:255|unique:users,email,' . $id,
                'phone' => 'sometimes|nullable|string|max:20',
                'is_verified' => 'sometimes|boolean',
                'phone_verified' => 'sometimes|boolean',
                'two_factor_enabled' => 'sometimes|boolean',
                'status' => 'sometimes|in:active,suspended,inactive',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user->update($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => [
                    'user' => $user
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('UserController update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verify($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->update([
                'is_verified' => true,
                'kyc_status' => 'verified',
                'kyc_verified_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User verified successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('UserController verify error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function suspend($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->update([
                'status' => 'suspended'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User suspended successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('UserController suspend error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to suspend user',
                'error' => $e->getMessage()
            ], 500);
        }
    }
public function updateBalance(Request $request, $id)
{
    try {
        Log::info('Update balance request received', [
            'user_id' => $id,
            'request_data' => $request->all(),
            'admin_id' => auth()->id()
        ]);

        $validator = Validator::make($request->all(), [
            'currency' => 'required|in:token,usdc',
            'amount' => 'required|numeric|min:0.000001',
            'type' => 'required|in:add,subtract',
            'note' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            Log::error('Balance update validation failed', [
                'errors' => $validator->errors()->toArray()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::find($id);
        
        if (!$user) {
            Log::error('User not found for balance update', ['user_id' => $id]);
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        Log::info('User found for balance update', [
            'user_id' => $user->id,
            'username' => $user->username
        ]);
        
        // Map currency to actual database fields
        $field = match($request->currency) {
            'token' => 'token_balance',
            'usdc' => 'usdc_balance',
            default => 'token_balance',
        };

        $currentBalance = $user->$field ?? 0;
        $amount = floatval($request->amount);
        
        Log::info('Balance calculation', [
            'field' => $field,
            'current_balance' => $currentBalance,
            'amount' => $amount,
            'operation' => $request->type
        ]);
        
        // Calculate new balance based on operation type
        if ($request->type === 'add') {
            $newBalance = $currentBalance + $amount;
        } else {
            // subtract operation
            $newBalance = max(0, $currentBalance - $amount);
            
            // Check if balance is sufficient for subtraction
            if ($amount > $currentBalance) {
                Log::warning('Insufficient balance for subtraction', [
                    'current_balance' => $currentBalance,
                    'requested_amount' => $amount
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient balance. User only has ' . $currentBalance . ' available.',
                    'current_balance' => $currentBalance,
                    'requested_amount' => $amount
                ], 400);
            }
        }

        // Update user balance
        $user->$field = $newBalance;
        $user->save();

        Log::info('User balance updated successfully', [
            'previous_balance' => $currentBalance,
            'new_balance' => $newBalance,
            'field' => $field
        ]);

        // Log the balance adjustment
        // Transaction::create([
        //     'user_id' => $id,
        //     'type' => 'admin_adjustment',
        //     'amount' => $request->type === 'subtract' ? -$amount : $amount,
        //     'currency' => $request->currency === 'usdc' ? 'USDC' : 'CMEME',
        //     'description' => "Admin balance adjustment: {$request->note}",
        //     'status' => 'completed',
        //     'metadata' => [
        //         'admin_id' => auth()->id(),
        //         'operation' => $request->type,
        //         'balance_type' => $request->currency,
        //         'previous_balance' => $currentBalance,
        //         'new_balance' => $newBalance,
        //         'reason' => $request->note,
        //         'adjusted_by_admin' => true
        //     ]
        // ]);

        Log::info('Transaction logged successfully');

        return response()->json([
            'success' => true,
            'message' => 'Balance updated successfully',
            'data' => [
                'user_id' => $user->id,
                'username' => $user->username,
                'currency' => $request->currency === 'usdc' ? 'USDC' : 'CMEME',
                'operation' => $request->type,
                'amount' => $amount,
                'previous_balance' => $currentBalance,
                'new_balance' => $newBalance,
                'field_updated' => $field
            ]
        ]);

    } catch (\Exception $e) {
        Log::error('UserController updateBalance error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Failed to update balance',
            'error' => $e->getMessage()
        ], 500);
    }
}

    public function getUserTransactions($id)
    {
        try {
            $user = User::findOrFail($id);
            $transactions = Transaction::where('user_id', $id)
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'transactions' => $transactions
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('UserController getUserTransactions error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUserTrades($id)
    {
        try {
            $user = User::findOrFail($id);
            $trades = P2PTrade::where('seller_id', $id)
                ->orWhere('buyer_id', $id)
                ->with(['seller', 'buyer', 'dispute'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'trades' => $trades
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('UserController getUserTrades error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trades',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = User::findOrFail($id);
            
            // In a real application, you might want to soft delete or archive
            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('UserController destroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function exportUsers()
    {
        try {
            $users = User::all();
            
            $csvData = "ID,Username,Email,Phone,Status,KYC Status,Joined Date,Last Login,Token Balance,USDC Balance\n";
            
            foreach ($users as $user) {
                $csvData .= "{$user->id},{$user->username},{$user->email},{$user->phone},{$user->status},{$user->kyc_status},{$user->created_at},{$user->last_login_at},{$user->token_balance},{$user->usdc_balance}\n";
            }

            return response($csvData)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', 'attachment; filename="users-export-' . date('Y-m-d') . '.csv"');

        } catch (\Exception $e) {
            Log::error('UserController exportUsers error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export users',
                'error' => $e->getMessage()
            ], 500);
        }
    }


 public function getUserTransaction($id)
{
    try {
        $user = User::findOrFail($id);
        
        $query = Transaction::where('user_id', $id)
            ->orderBy('created_at', 'desc');

        // Add search functionality
        if (request()->has('search') && !empty(request()->search)) {
            $search = request()->search;
            $query->where(function($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%");
            });
        }

        // Filter by type
        if (request()->has('type') && request()->type !== 'all') {
            $query->where('type', request()->type);
        }

        $transactions = $query->paginate(request()->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'transactions' => $transactions
            ]
        ]);
    } catch (\Exception $e) {
        Log::error('UserController getUserTransactions error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch transactions',
            'error' => $e->getMessage()
        ], 500);
    }
}

    
}