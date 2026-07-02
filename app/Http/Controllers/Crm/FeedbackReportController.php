<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Crm\Traits\FilterableBranch;
use App\Http\Requests\Crm\StoreFeedbackReportRequest;
use App\Http\Requests\Crm\UpdateFeedbackReportRequest;
use App\Models\FeedbackReport;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FeedbackReportController extends Controller
{
    use FilterableBranch;

    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedBranchId = $this->resolveSelectedBranchId($request->get('branch_id'));
        $selectedType = $request->get('type');
        $selectedStatus = $request->get('status');

        $branches = $this->resolveBranches();
        $query = $this->applyBranchScope(FeedbackReport::with(['branch', 'creator', 'reviewer']), $selectedBranchId);

        $query->when($selectedType, fn($q, $v) => $q->where('type', $v));
        $query->when($selectedStatus, fn($q, $v) => $q->where('status', $v));
        $query->when($request->get('search'), fn($q, $v) => $q->where(function($q) use ($v) {
            $q->where('title', 'like', "%{$v}%")
              ->orWhere('description', 'like', "%{$v}%");
        }));

        $sortField = $request->get('sort', 'created_at');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['created_at', 'title', 'type', 'status'];
        if (!in_array($sortField, $allowedSorts)) $sortField = 'created_at';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $perPage = $request->get('per_page', '15');
        if ($perPage === 'all') {
            $records = $query->orderBy($sortField, $sortDir)->get();
        } else {
            $records = $query->orderBy($sortField, $sortDir)->paginate((int) $perPage)->withQueryString();
        }

        return view('crm.feedback-reports.index', compact('records', 'branches', 'selectedBranchId', 'selectedType', 'selectedStatus', 'sortField', 'sortDir', 'perPage'));
    }

    public function create()
    {
        $user = Auth::user();
        $branches = $this->resolveBranches();
        return view('crm.feedback-reports.create', compact('branches'));
    }

    public function store(StoreFeedbackReportRequest $request)
    {
        $user = Auth::user();
        $data = $request->validated();

        $data['user_id'] = $user->id;

        if (!$user->canViewAllBranches()) {
            $data['branch_id'] = $user->branch_id;
        }

        if (empty($data['branch_id'])) {
            return redirect()->back()->withInput()->with('error', 'Pilih cabang terlebih dahulu.');
        }

        FeedbackReport::create($data);

        return redirect()->route('feedback-reports.index', array_filter($request->only(['branch_id', 'type', 'status'])))
            ->with('success', 'Laporan berhasil dikirim.');
    }

    public function edit(FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $feedbackReport->branch_id !== $user->branch_id) {
            abort(403);
        }

        $branches = $this->resolveBranches();
        return view('crm.feedback-reports.edit', compact('feedbackReport', 'branches'));
    }

    public function update(UpdateFeedbackReportRequest $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $feedbackReport->branch_id !== $user->branch_id) {
            abort(403);
        }

        $feedbackReport->update($request->validated());

        return redirect()->route('feedback-reports.index', array_filter($request->only(['branch_id', 'type', 'status'])))
            ->with('success', 'Laporan berhasil diperbarui.');
    }

    public function destroy(Request $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches() && $feedbackReport->branch_id !== $user->branch_id) {
            abort(403);
        }

        $feedbackReport->delete();

        return redirect()->route('feedback-reports.index', array_filter($request->only(['branch_id', 'type', 'status'])))
            ->with('success', 'Laporan berhasil dihapus.');
    }

    public function approve(Request $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches()) {
            abort(403);
        }

        $feedbackReport->update([
            'status' => 'approved',
            'admin_note' => $request->input('admin_note', $feedbackReport->admin_note),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Laporan disetujui.');
    }

    public function reject(Request $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches()) {
            abort(403);
        }

        $feedbackReport->update([
            'status' => 'rejected',
            'admin_note' => $request->input('admin_note', $feedbackReport->admin_note),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Laporan ditolak.');
    }

    public function markImplemented(FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches()) {
            abort(403);
        }

        $feedbackReport->update([
            'status' => 'implemented',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Laporan ditandai sebagai sudah diimplementasi.');
    }

    public function markFixed(FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches()) {
            abort(403);
        }

        $feedbackReport->update([
            'status' => 'fixed',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Bug ditandai sebagai sudah diperbaiki.');
    }
}
