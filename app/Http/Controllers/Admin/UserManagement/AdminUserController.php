<?php

namespace App\Http\Controllers\Admin\UserManagement;

use App\Models\User;
use Illuminate\Support\Str;
use App\Exports\UsersExport;
use App\Imports\UsersImport;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\Admin\AdminUserResource;
use App\Http\Resources\Admin\AdminUserCollection;
use App\Http\Requests\Admin\UserManagement\AdminUserUpdateRequest;
use App\Http\Requests\Admin\UserManagement\AdminUserResetPasswordRequest;
use App\Http\Requests\Admin\UserManagement\AdminUserBulkActionRequest;
use App\Http\Requests\Admin\UserManagement\AdminUserImportRequest;

class AdminUserController extends Controller
{
    // Create new admin account ONLY in admins table
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:admins,email',
            'password' => 'required|string|min:6',
        ]);

        $admin = \App\Models\Admin::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'email_verified_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'created_at' => $admin->created_at,
            ],
            'message' => 'Admin account created successfully',
        ], 201);
    }

    // List users with filters & pagination
public function index(Request $request)
{
    $query = User::query();

    // Global search across multiple fields
    if ($request->filled('search')) {
        $search = $request->search;
        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
            // Add more fields if needed
        });
    }

    // Individual filters
    if ($request->filled('name')) {
        $query->where('name', 'like', '%' . $request->name . '%');
    }

    if ($request->filled('email')) {
        $query->where('email', 'like', '%' . $request->email . '%');
    }

    if ($request->filled('phone')) {
        $query->where('phone', 'like', '%' . $request->phone . '%');
    }

    if ($request->filled('role') && $request->role !== 'undefined') {
        $query->where('role', $request->role);
    }

    if ($request->has('is_active')) {
        $query->where('is_active', $request->boolean('is_active'));
    }

    if ($request->has('is_blocked')) {
        $query->where('is_blocked', $request->boolean('is_blocked'));
    }

    // Sorting
    $sortBy = $request->input('sort_by', 'id'); // default sort by id
    $sortOrder = $request->input('sort_order', 'desc');
    $query->orderBy($sortBy, $sortOrder);

    // Pagination
    $perPage = $request->input('per_page', 20);
    $users = $query->paginate($perPage);


    return response()->json(['data'=>new AdminUserCollection($users)]);
   
}


    // Show single user
    public function show($id)
    {
        $user = User::findOrFail($id);
        return new AdminUserResource($user);
    }


    public function update(AdminUserUpdateRequest $request, $id)
    {
        $user = User::findOrFail($id);

        // Update fields
        $user->fill($request->only([
            'name', 'email', 'role', 'is_active', 'is_blocked', 'notes', 'phone', 'credit_balance', 'used_credits'
        ]));

        $user->save();

        return response()->json([
            'data' => new AdminUserResource($user),
            'Message' => 'User updated successfully',
            'isError' => false,
            'status_code' => 200
        ]);
    }

    // Add / Set credit balance directly
    public function addCredit(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric',
            'type' => 'nullable|string|in:add,set'
        ]);

        $user = User::findOrFail($id);
        $amount = (float) $request->input('amount');
        $type = $request->input('type', 'add');

        $oldBalance = (float) ($user->credit_balance ?? 0);
        $newBalance = $type === 'set' ? $amount : ($oldBalance + $amount);
        $diff = round($newBalance - $oldBalance, 2);

        $note = $diff >= 0 ? "Admin recharge to user" : "Admin debited from user";

        if ($diff > 0) {
            // Case 1: Admin Recharge / Added
            $user->credit_balance = $newBalance;

            // Record in payments table so totalRecharged and Statement reflect it
            $user->payments()->create([
                'payable_type' => get_class($user),
                'payable_id' => $user->id,
                'amount' => $diff,
                'status' => 'paid',
                'currency' => 'usd',
                'payment_method' => 'admin_recharge',
                'transaction_id' => 'adm_rec_' . uniqid(),
                'meta' => [
                    'description' => $note,
                    'note' => $note . " (+$" . number_format($diff, 2) . " USD)",
                    'old_balance' => $oldBalance,
                    'new_balance' => $newBalance,
                ]
            ]);
        } elseif ($diff < 0) {
            // Case 2: Admin Debit / Reduced
            $debitAmount = abs($diff);
            $user->credit_balance = $newBalance;
            // Increment used_credits so totalRecharged - used_credits stays consistent with newBalance
            $user->used_credits = round((float) ($user->used_credits ?? 0) + $debitAmount, 2);

            // Record in payments table with payment_method 'admin_debit'
            $user->payments()->create([
                'payable_type' => get_class($user),
                'payable_id' => $user->id,
                'amount' => $debitAmount,
                'status' => 'paid',
                'currency' => 'usd',
                'payment_method' => 'admin_debit',
                'transaction_id' => 'adm_deb_' . uniqid(),
                'meta' => [
                    'description' => $note,
                    'note' => $note . " (-$" . number_format($debitAmount, 2) . " USD)",
                    'old_balance' => $oldBalance,
                    'new_balance' => $newBalance,
                ]
            ]);
        }

        // Auto append note to user's notes field
        $timestamp = now()->toDateTimeString();
        $formattedDiff = ($diff >= 0 ? "+$" : "-$") . number_format(abs($diff), 2);
        $logLine = "[{$timestamp}] {$note}: {$formattedDiff} (Balance: $" . number_format($newBalance, 2) . ")";
        $user->notes = $user->notes ? ($user->notes . "\n" . $logLine) : $logLine;

        $user->save();

        return response()->json([
            'data' => new AdminUserResource($user),
            'message' => "Credit balance updated successfully ({$note})",
            'credit_balance' => $user->credit_balance,
            'note' => $note,
        ]);
    }



    // Activate / Deactivate
    public function toggleActive($id)
    {
        $user = User::findOrFail($id);
        $user->is_active = !$user->is_active;
        $user->save();

        return response()->json([
            'message' => $user->is_active ? 'User activated' : 'User deactivated'
        ]);
    }

    // Block / Unblock
    public function toggleBlock($id)
    {
        $user = User::findOrFail($id);
        $user->is_blocked = !$user->is_blocked;
        $user->save();

        return response()->json([
            'message' => $user->is_blocked ? 'User blocked' : 'User unblocked'
        ]);
    }

    // Reset password
    public function resetPassword(AdminUserResetPasswordRequest $request, $id)
    {
        $user = User::findOrFail($id);
        $newPassword = $request->input('password', Str::random(10));
        $user->password = Hash::make($newPassword);
        $user->save();

        // Optionally send email notification

        return response()->json([
            'message' => 'Password reset successfully',
            'new_password' => $newPassword
        ]);
    }

    // Verify email manually
    public function verifyEmail($id)
    {
        $user = User::findOrFail($id);
        $user->email_verified_at = now();
        $user->save();

        return response()->json(['message' => 'Email verified']);
    }

    // Add / update admin notes
    public function updateNotes(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $user->notes = $request->input('notes', $user->notes);
        $user->save();

        return response()->json(['message' => 'Notes updated']);
    }

    // Bulk action
    public function bulkAction(AdminUserBulkActionRequest $request)
    {
        $action = $request->input('action'); // activate, deactivate, block, unblock
        $userIds = $request->input('user_ids', []);

        if (empty($userIds)) {
            return response()->json(['message' => 'No users selected'], 400);
        }

        $updateData = match ($action) {
            'activate' => ['is_active' => true],
            'deactivate' => ['is_active' => false],
            'block' => ['is_blocked' => true],
            'unblock' => ['is_blocked' => false],
            default => null,
        };

        if ($updateData) {
            User::whereIn('id', $userIds)->update($updateData);
        }

        return response()->json(['message' => 'Bulk action completed successfully']);
    }

    // Delete user (soft delete recommended)
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete(); // Soft delete if `SoftDeletes` trait is used
        return response()->json(['message' => 'User deleted']);
    }


    public function export()
    {
        return Excel::download(new UsersExport, 'users.xlsx');
    }

    public function import(AdminUserImportRequest $request)
    {
        Excel::import(new UsersImport, $request->file('file'));
        return response()->json(['message' => 'Users imported successfully']);
    }

    public function impersonate($id)
    {
        $user = User::findOrFail($id);
        
        // Generate a JWT token for the user targeting the 'user' guard
        // We use fromUser to get a token for a specific user model
        $token = JWTAuth::fromUser($user);
        
        return response()->json([
            'message' => 'Impersonation token generated',
            'token' => $token,
            'user' => $user
        ]);
    }

    // Get list of admin accounts from admins table
    public function getAdmins(Request $request)
    {
        $admins = \App\Models\Admin::select('id', 'name', 'email', 'created_at', 'updated_at')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'data' => $admins,
            'message' => 'Admin list retrieved successfully',
        ]);
    }
}
