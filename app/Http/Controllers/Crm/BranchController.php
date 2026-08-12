<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\CollaborationNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function __construct(private readonly CollaborationNotificationService $notifications) {}

    public function index()
    {
        $this->ensureSuperadmin();
        $branches = Branch::withCount(['contentItems', 'users as members_count'])->where('is_active', true)->forDropdown()->get();

        return view('crm.branches.index', compact('branches'));
    }

    public function edit(Branch $branch)
    {
        $this->ensureSuperadmin();
        abort_unless($branch->is_active, 404);

        return view('crm.branches.edit', compact('branch'));
    }

    public function update(Request $request, Branch $branch)
    {
        $this->ensureSuperadmin();
        abort_unless($branch->is_active, 404);
        $name = Str::squish($request->string('name')->toString());
        validator(['name' => $name], ['name' => ['required', 'string', 'max:255']])->validate();

        DB::transaction(function () use ($branch, $name): void {
            $lockedBranch = Branch::query()->lockForUpdate()->findOrFail($branch->id);
            abort_unless($lockedBranch->is_active, 404);
            $duplicate = Branch::query()
                ->where('is_active', true)
                ->whereKeyNot($lockedBranch->id)
                ->lockForUpdate()
                ->pluck('name')
                ->contains(fn (string $existingName): bool => mb_strtolower(Str::squish($existingName)) === mb_strtolower($name));

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => 'Nama cabang sudah digunakan.']);
            }

            $oldName = $lockedBranch->name;
            if ($oldName === $name) {
                return;
            }

            $lockedBranch->update(['name' => $name]);
            ActivityLog::create([
                'causer_id' => Auth::id(),
                'subject_type' => Branch::class,
                'subject_id' => $lockedBranch->id,
                'event' => 'branch_renamed',
                'description' => 'Nama cabang diubah dari '.$oldName.' menjadi '.$name,
                'properties' => [
                    'branch_id' => $lockedBranch->id,
                    'old_name' => $oldName,
                    'new_name' => $name,
                    'actor_id' => Auth::id(),
                ],
            ]);
        });

        return redirect()->route('branches.index')->with('success', 'Nama cabang berhasil diperbarui.');
    }

    public function assignForm(Branch $branch)
    {
        $this->ensureSuperadmin();
        abort_unless($branch->is_active, 404);
        $members = $branch->users()->with('role')->orderBy('name')->get();
        $availableUsers = User::where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('crm.branches.assign', compact('branch', 'members', 'availableUsers'));
    }

    public function assignStore(Request $request, Branch $branch)
    {
        $this->ensureSuperadmin();
        abort_unless($branch->is_active, 404);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'can_edit' => ['nullable', 'boolean'],
            'can_sync' => ['nullable', 'boolean'],
            'can_manage_members' => ['nullable', 'boolean'],
        ]);

        $user = User::where('is_active', true)->findOrFail($data['user_id']);
        $user->branches()->syncWithoutDetaching([$branch->id => [
            'can_view' => true,
            'can_edit' => (bool) ($data['can_edit'] ?? false),
            'can_sync' => (bool) ($data['can_sync'] ?? false),
            'can_manage_members' => (bool) ($data['can_manage_members'] ?? false),
        ]]);
        if (! $user->branch_id) {
            $user->update(['branch_id' => $branch->id]);
        }
        $this->logMembership($user, $branch, 'membership_added');
        $this->notifications->membershipChanged($user, 'Akses cabang '.$branch->name.' Anda diperbarui.');

        return redirect()->route('branches.assign', $branch)->with('success', 'Anggota cabang berhasil ditambahkan.');
    }

    public function removeAdmin(Request $request, User $user)
    {
        $this->ensureSuperadmin();
        $branchId = (int) $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']])['branch_id'];
        if ($user->isSuperadmin()) {
            return back()->with('error', 'Tidak dapat menghapus Super Admin.');
        }
        if ((int) $user->branch_id === $branchId) {
            return back()->with('error', 'Pindahkan cabang utama user terlebih dahulu sebelum menghapus membership ini.');
        }

        $user->branches()->detach($branchId);
        $branch = Branch::find($branchId);
        if ($branch) {
            $this->logMembership($user, $branch, 'membership_removed');
            $this->notifications->membershipChanged($user, 'Akses cabang '.$branch->name.' Anda dihapus.');
        }

        return back()->with('success', 'Akses cabang berhasil dihapus tanpa menghapus akun user.');
    }

    private function ensureSuperadmin(): void
    {
        if (! Auth::user()->isSuperadmin()) {
            abort(403);
        }
    }

    private function logMembership(User $user, Branch $branch, string $event): void
    {
        ActivityLog::create([
            'causer_id' => Auth::id(),
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event' => $event,
            'description' => 'Membership cabang '.$branch->name.' diperbarui',
            'properties' => ['branch_id' => $branch->id],
        ]);
    }
}
