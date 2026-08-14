<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ReviewFeedbackReportRequest;
use App\Http\Requests\Crm\StoreFeedbackReportRequest;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Services\CollaborationNotificationService;
use App\Services\FeedbackDiscordService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedbackReportController extends Controller
{
    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly CollaborationNotificationService $notifications,
        private readonly FeedbackDiscordService $discord,
    ) {}

    public function store(StoreFeedbackReportRequest $request)
    {
        $user = $request->user();
        $branch = $this->workspaceAccess->resolveRequestedBranch($user, $request->integer('branch_id'));
        abort_unless($branch && $this->workspaceAccess->canViewBranch($user, $branch), 403);

        $data = $request->safe()->except('screenshot');
        $data['user_id'] = $user->id;
        $data['branch_id'] = $branch->id;
        $data['active_branch_id'] = $branch->id;
        $data['status'] = 'pending';
        $data['priority'] = 'medium';
        $data['page_url'] = $this->safePageUrl($request->input('page_url'));
        $data['app_version'] = config('app.version');

        if ($request->hasFile('screenshot')) {
            $data += $this->storeScreenshot($request->file('screenshot'));
        }

        try {
            $report = FeedbackReport::create($data)->load(['creator', 'branch']);
        } catch (\Throwable $exception) {
            if (filled($data['screenshot_path'] ?? null)) {
                Storage::disk('local')->delete($data['screenshot_path']);
            }
            throw $exception;
        }

        $this->notifications->feedbackSubmitted($report);
        $this->discord->send($report);

        return response()->json([
            'ok' => true,
            'message' => 'Laporan berhasil disimpan.',
            'report' => $this->resource($report),
        ], 201);
    }

    public function history(Request $request)
    {
        $reports = FeedbackReport::with(['branch'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (FeedbackReport $report) => $this->resource($report));

        return response()->json(['ok' => true, 'reports' => $reports]);
    }

    public function index(Request $request)
    {
        $this->ensureReviewer($request->user());
        $query = FeedbackReport::with(['creator', 'branch', 'assignee'])->latest();
        if (! $request->user()->isSuperadmin()) {
            $query->whereIn('branch_id', $this->workspaceAccess->accessibleBranchIds($request->user()));
        }
        $query->when($request->type, fn ($builder, $value) => $builder->where('type', $value));
        $query->when($request->status, fn ($builder, $value) => $builder->where('status', $value));
        $query->when($request->branch_id, fn ($builder, $value) => $builder->where('branch_id', $value));
        $query->when($request->module, fn ($builder, $value) => $builder->where('module', $value));
        $query->when($request->search, fn ($builder, $value) => $builder->where(function ($nested) use ($value) {
            $nested->where('title', 'like', "%{$value}%")->orWhere('description', 'like', "%{$value}%");
        }));

        return view('crm.feedback-reports.index', [
            'reports' => $query->paginate(20)->withQueryString(),
            'branches' => $this->workspaceAccess->accessibleBranches($request->user()),
            'modules' => FeedbackReport::whereNotNull('module')->distinct()->orderBy('module')->pluck('module'),
        ]);
    }

    public function show(Request $request, FeedbackReport $feedbackReport)
    {
        $this->authorize('review', $feedbackReport);
        $feedbackReport->load(['creator', 'branch', 'reviewer', 'assignee']);

        return view('crm.feedback-reports.show', [
            'report' => $feedbackReport,
            'allowedStatuses' => $feedbackReport->allowedTransitions(),
            'assignees' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'branch_id']),
        ]);
    }

    public function review(ReviewFeedbackReportRequest $request, FeedbackReport $feedbackReport)
    {
        $this->authorize('review', $feedbackReport);
        $data = $request->validated();
        if (! in_array($data['status'], $feedbackReport->allowedTransitions(), true)) {
            $message = 'Status laporan sudah berubah atau transisi status yang dipilih tidak diperbolehkan. Silakan muat ulang dan coba lagi.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 409)
                : back()->withInput()->with('error', $message);
        }
        if (filled($data['assigned_to'] ?? null)) {
            $assignee = User::where('is_active', true)->findOrFail($data['assigned_to']);
            abort_unless($this->workspaceAccess->canViewBranch($assignee, (int) $feedbackReport->branch_id), 422);
        }

        $statusChanged = $feedbackReport->status !== $data['status'];
        $assignmentChanged = (int) $feedbackReport->assigned_to !== (int) ($data['assigned_to'] ?? 0);
        $terminal = in_array($data['status'], ['rejected', 'implemented', 'fixed', 'closed'], true);
        $feedbackReport->update($data + [
            'reviewed_by' => $feedbackReport->reviewed_by ?: $request->user()->id,
            'reviewed_at' => $feedbackReport->reviewed_at ?: now(),
            'resolved_at' => $terminal ? ($feedbackReport->resolved_at ?: now()) : null,
        ]);
        $feedbackReport->load(['creator', 'assignee']);

        if ($statusChanged) {
            $this->notifications->feedbackStatusChanged($feedbackReport);
        }
        if ($assignmentChanged && $feedbackReport->assigned_to) {
            $this->notifications->feedbackAssigned($feedbackReport);
        }

        return redirect()->route('feedback-reports.show', $feedbackReport)->with('success', 'Laporan berhasil diperbarui.');
    }

    public function screenshot(Request $request, FeedbackReport $feedbackReport): StreamedResponse
    {
        $this->authorize('view', $feedbackReport);
        abort_unless($feedbackReport->screenshot_path && Storage::disk('local')->exists($feedbackReport->screenshot_path), 404);

        return Storage::disk('local')->download(
            $feedbackReport->screenshot_path,
            $feedbackReport->screenshot_name ?: 'screenshot.'.$this->extensionForMime($feedbackReport->screenshot_mime),
            ['Content-Type' => $feedbackReport->screenshot_mime],
        );
    }

    private function storeScreenshot($file): array
    {
        $mime = (string) $file->getMimeType();
        $extension = $this->extensionForMime($mime);
        $path = 'feedback-screenshots/'.Str::uuid().'.'.$extension;
        $contents = file_get_contents($file->getRealPath());
        if (function_exists('imagecreatefromstring') && $image = @imagecreatefromstring($contents)) {
            ob_start();
            match ($extension) {
                'png' => imagepng($image),
                'webp' => imagewebp($image, null, 88),
                default => imagejpeg($image, null, 88),
            };
            $contents = (string) ob_get_clean();
            imagedestroy($image);
        }
        Storage::disk('local')->put($path, $contents);

        return [
            'screenshot_path' => $path,
            'screenshot_name' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 100).'.'.$extension,
            'screenshot_mime' => $mime,
            'screenshot_size' => strlen($contents),
        ];
    }

    private function extensionForMime(?string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function safePageUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! $parts || ($parts['host'] ?? null) !== parse_url(config('app.url'), PHP_URL_HOST)) {
            return null;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].($parts['path'] ?? '/');
    }

    private function resource(FeedbackReport $report): array
    {
        return [
            'id' => $report->id,
            'type' => $report->type,
            'type_label' => $report->typeLabel(),
            'title' => $report->title,
            'module' => $report->module,
            'status' => $report->status,
            'status_label' => $report->statusLabel(),
            'admin_note' => $report->admin_note,
            'branch_name' => $report->branch?->name,
            'created_at' => $report->created_at?->locale('id')->translatedFormat('d M Y, H:i'),
            'resolved_at' => $report->resolved_at?->locale('id')->translatedFormat('d M Y, H:i'),
            'has_screenshot' => filled($report->screenshot_path),
        ];
    }

    private function ensureReviewer(User $user): void
    {
        abort_unless($user->isSuperadmin() || $user->hasRole('pusat'), 403);
    }
}
