<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreFeedbackReportRequest;
use App\Models\FeedbackReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FeedbackReportController extends Controller
{
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
                : response()->json(['ok' => false, 'error' => 'Pilih cabang terlebih dahulu.'], 422);
        }

        FeedbackReport::create($data);

        return response()->json(['ok' => true, 'message' => 'Laporan berhasil dikirim.']);
    }

    public function fetchRecent(Request $request)
    {
        $user = Auth::user();
        $isSuper = $user->isSuperadmin();

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

    public function fetchHistory(Request $request)
    {
        $user = Auth::user();
        $isSuper = $user->isSuperadmin();

        $query = FeedbackReport::with(['creator', 'branch', 'reviewer']);

        if (!$isSuper) {
            $query->where('user_id', $user->id);
        }

        $perPage = min((int) $request->get('per_page', 20), 50);
        $reports = $query->orderBy('created_at', 'desc')->paginate($perPage)->through(function ($r) {
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
                'reviewed_at' => $r->reviewed_at ? $r->reviewed_at->format('d M Y H:i') : null,
                'reviewer_name' => $r->reviewer->name ?? null,
            ];
        });

        return response()->json([
            'ok' => true,
            'reports' => $reports->items(),
            'next_page_url' => $reports->nextPageUrl(),
            'has_more' => $reports->hasMorePages(),
        ]);
    }

    public function approve(Request $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->isSuperadmin()) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 403);
        }

        $feedbackReport->update([
            'status' => 'approved',
            'admin_note' => $request->input('admin_note', $feedbackReport->admin_note),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['ok' => true, 'message' => 'Laporan disetujui.']);
    }

    public function reject(Request $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->isSuperadmin()) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 403);
        }

        $feedbackReport->update([
            'status' => 'rejected',
            'admin_note' => $request->input('admin_note', $feedbackReport->admin_note),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['ok' => true, 'message' => 'Laporan ditolak.']);
    }

    public function markImplemented(Request $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->isSuperadmin()) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 403);
        }

        $feedbackReport->update([
            'status' => 'implemented',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['ok' => true, 'message' => 'Ditandai sebagai implementasi.']);
    }

    public function markFixed(Request $request, FeedbackReport $feedbackReport)
    {
        $user = Auth::user();
        if (!$user->isSuperadmin()) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 403);
        }

        $feedbackReport->update([
            'status' => 'fixed',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['ok' => true, 'message' => 'Ditandai sebagai fixed.']);
    }
}
