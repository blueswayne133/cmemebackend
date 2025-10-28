<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminTransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = Transaction::with('user');
        
        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('user', function($userQuery) use ($search) {
                      $userQuery->where('username', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }
        
        // Type filter
        if ($request->has('type') && $request->type) {
            $query->where('type', $request->type);
        }
        
        // User filter
        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }
        
        // Date range filter
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        
        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        
        // Sort
        $sortField = $request->get('sort_field', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);
        
        $transactions = $query->paginate($request->get('per_page', 15));
        
        return response()->json([
            'success' => true,
            'data' => $transactions,
            'types' => Transaction::getTypes()
        ]);
    }
    
    public function show(Transaction $transaction)
    {
        $transaction->load('user');
        
        return response()->json([
            'success' => true,
            'data' => $transaction
        ]);
    }
    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'type' => ['required', Rule::in(array_keys(Transaction::getTypes()))],
            'amount' => 'required|numeric|min:0.00000001',
            'description' => 'required|string|max:500',
            'metadata' => 'nullable|array'
        ]);
        
        $transaction = Transaction::create($validated);
        $transaction->load('user');
        
        // Update user balance if needed
        $this->updateUserBalance($transaction);
        
        return response()->json([
            'success' => true,
            'message' => 'Transaction created successfully',
            'data' => $transaction
        ], 201);
    }
    
    public function update(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(array_keys(Transaction::getTypes()))],
            'amount' => 'sometimes|numeric|min:0.00000001',
            'description' => 'sometimes|string|max:500',
            'metadata' => 'nullable|array'
        ]);
        
        $transaction->update($validated);
        $transaction->load('user');
        
        // Update user balance if amount changed
        if ($request->has('amount') && $request->amount != $transaction->getOriginal('amount')) {
            $this->updateUserBalance($transaction);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Transaction updated successfully',
            'data' => $transaction
        ]);
    }
    
    public function destroy(Transaction $transaction)
    {
        $transaction->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Transaction deleted successfully'
        ]);
    }
    
    public function getStats()
    {
        $stats = [
            'total_transactions' => Transaction::count(),
            'total_volume' => (float) Transaction::sum('amount'),
            'today_transactions' => Transaction::whereDate('created_at', today())->count(),
            'today_volume' => (float) Transaction::whereDate('created_at', today())->sum('amount'),
            'type_distribution' => Transaction::select('type', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as volume'))
                ->groupBy('type')
                ->get()
                ->keyBy('type')
        ];
        
        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
    
    private function updateUserBalance(Transaction $transaction)
    {
        $user = $transaction->user;
        
        if ($transaction->isEarning()) {
            // For earning types, update token balance
            $user->token_balance = max(0, $user->token_balance + $transaction->amount);
        } elseif ($transaction->type === Transaction::TYPE_WITHDRAWAL) {
            // For withdrawals, deduct from appropriate balance
            $user->token_balance = max(0, $user->token_balance - $transaction->amount);
        } elseif ($transaction->type === Transaction::TYPE_DEPOSIT) {
            // For deposits, add to appropriate balance
            $user->token_balance += $transaction->amount;
        }
        
        $user->save();
    }
}