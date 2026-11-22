<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\RevokeAccessGrantRequest;
use App\Http\Requests\Crm\StoreAccessGrantRequest;
use App\Models\AccessGrant;
use App\Models\User;
use App\Services\AccessGrantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AccessGrantController extends Controller
{
    public function __construct(private readonly AccessGrantService $grants) {}

    public function index(): View
    {
        abort_unless(request()->user()->isSuperadmin(), 403);

        return view('crm.access-grants.index', ['grants' => AccessGrant::query()->with(['grantee', 'subject', 'grantedBy', 'revokedBy'])->latest()->paginate(30), 'users' => User::query()->where('is_active', true)->orderBy('name')->get(), 'modules' => AccessGrantService::MODULES]);
    }

    public function store(StoreAccessGrantRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['grantee'] = $request->grantee();
        $data['subject'] = $request->subject();
        unset($data['grantee_user_id'], $data['subject_user_id']);
        $this->grants->create($request->user(), $data);

        return to_route('admin.access-grants.index')->with('success', 'Izin Akses Khusus berhasil diberikan.');
    }

    public function revoke(RevokeAccessGrantRequest $request, AccessGrant $accessGrant): RedirectResponse
    {
        $this->grants->revoke($request->user(), $accessGrant, $request->validated('reason'));

        return to_route('admin.access-grants.index')->with('success', 'Izin Akses Khusus berhasil dicabut.');
    }
}
