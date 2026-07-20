<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class BranchController extends Controller
{
    public function index()
    {
        $this->ensureSuperadmin();
        $branches = Branch::withCount(['contentItems', 'primaryUsers as admins_count'])->where('is_active', true)->forDropdown()->get();
        return view('crm.branches.index', compact('branches'));
    }

    public function assignForm(Branch $branch)
    {
        $this->ensureSuperadmin();
        $admins = User::where('branch_id', $branch->id)->get();
        $role = Role::where('slug', 'admin')->first();
        $availableUsers = User::whereNull('branch_id')->orWhere('branch_id', '!=', $branch->id)->get();
        return view('crm.branches.assign', compact('branch', 'admins', 'availableUsers', 'role'));
    }

    public function assignStore(Request $request, Branch $branch)
    {
        $this->ensureSuperadmin();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $role = Role::where('slug', 'admin')->first();

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        return redirect()->route('branches.assign', $branch)->with('success', 'Admin cabang berhasil ditambahkan.');
    }

    public function removeAdmin(User $user)
    {
        $this->ensureSuperadmin();
        if ($user->isSuperadmin()) {
            return back()->with('error', 'Tidak dapat menghapus Super Admin.');
        }
        $user->update(['branch_id' => null]);
        return back()->with('success', 'Admin cabang berhasil dihapus.');
    }

    private function ensureSuperadmin(): void
    {
        if (!Auth::user()->isSuperadmin()) {
            abort(403);
        }
    }
}
