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

        if (!$user->isSuperadmin()) {
            $data['branch_id'] = $user->branch_id;
        }

        if (empty($data['branch_id'])) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => 'Pilih cabang terlebih dahulu.'], 422)
                : redirect()->back()->withInput()->with('error', 'Pilih cabang terlebih dahulu.');
        }

        FeedbackReport::create($data);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Laporan berhasil dikirim.']);
        }

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

    public function fetchRecent(Request $request)
    {
        $user = Auth::user();
        $isSuper = $user->canViewAllBranches();

        $query = FeedbackReport::with(['creator', 'branch']);

        if ($isSuper) {
            $query->where('status', 'pending');
        } else {
            $query->where('user_id', $user->id);
        }

        $reports = $query->orderBy('created_at', 'desc')->take(10)->get()->map(function ($r) {
            return [
                'id' => $r->id,
                'type' => $r->type,
                'title' => $r->title,
                'description' => $r->description,
                'status' => $r->status,
                'creator_name' => $r->creator->name ?? '—',
                'branch_name' => $r->branch->name ?? '—',
                'admin_note' => $r->admin_note,
                'created_at' => $r->created_at->format('d M Y H:i'),
            ];
        });

        $pendingCount = FeedbackReport::where('status', 'pending')
            ->when(!$isSuper, fn($q) => $q->where('user_id', $user->id))
            ->count();

        return response()->json([
            'ok' => true,
            'reports' => $reports,
            'pending_count' => $pendingCount,
            'is_superadmin' => $isSuper,
        ]);
    }

    public function approve(Request $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches()) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => 'Unauthorized'], 403)
                : abort(403);
        }

        $feedbackReport->update([
            'status' => 'approved',
            'admin_note' => $request->input('admin_note', $feedbackReport->admin_note),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Laporan disetujui.']);
        }

        return redirect()->back()->with('success', 'Laporan disetujui.');
    }

    public function reject(Request $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches()) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => 'Unauthorized'], 403)
                : abort(403);
        }

        $feedbackReport->update([
            'status' => 'rejected',
            'admin_note' => $request->input('admin_note', $feedbackReport->admin_note),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Laporan ditolak.']);
        }

        return redirect()->back()->with('success', 'Laporan ditolak.');
    }

    public function markImplemented(Request $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches()) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => 'Unauthorized'], 403)
                : abort(403);
        }

        $feedbackReport->update([
            'status' => 'implemented',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Ditandai sebagai implementasi.']);
        }

        return redirect()->back()->with('success', 'Laporan ditandai sebagai sudah diimplementasi.');
    }

    public function markFixed(Request $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->canViewAllBranches()) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => 'Unauthorized'], 403)
                : abort(403);
        }

        $feedbackReport->update([
            'status' => 'fixed',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Ditandai sebagai fixed.']);
        }

        return redirect()->back()->with('success', 'Bug ditandai sebagai sudah diperbaiki.');
    }
}
