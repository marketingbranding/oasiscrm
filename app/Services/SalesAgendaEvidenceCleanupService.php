<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SalesAgendaEvidenceCleanupService
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function cleanup(Request $request, ContentItem $agenda, string $reason): void
    {
        $impersonating = $this->impersonation->isActive($request);
        $original = $impersonating ? $this->impersonation->originalUser($request) : null;
        $target = $impersonating ? $this->impersonation->targetUser($request) : null;
        abort_unless(! $impersonating || ($target && $request->user() && $target->is($request->user()) && $original), 403);
        $actor = $original ?: $request->user();
        abort_unless($actor instanceof User && $actor->hasPrimaryRole('superadmin') && $actor->isAccountActive() && $actor->is_active && $actor->hasVerifiedEmail() && ! $actor->must_change_password, 403);
        abort_unless($agenda->item_type === 'agenda' && $agenda->agenda_type === ContentItem::SALES_AGENDA_TYPE, 404);
        $evidence = $agenda->evidence()->get();
        if ($evidence->contains(fn ($item) => $item->archive_id || $item->archived_at || $item->purged_at || $item->archive_status === 'purged')) {
            throw new ConflictHttpException('Agenda memiliki bukti terarsip atau sudah dipurge. ZIP harus dipertahankan.');
        }

        DB::transaction(function () use ($request, $actor, $agenda, $evidence, $reason, $original) {
            foreach ($evidence as $item) {
                if ($item->storage_path && Storage::disk('agenda_evidence')->exists($item->storage_path) && ! Storage::disk('agenda_evidence')->delete($item->storage_path)) {
                    throw new ConflictHttpException('File bukti gagal dihapus.');
                }
                if ($item->file_path && $item->file_path !== $item->storage_path && Storage::disk('agenda_evidence')->exists($item->file_path) && ! Storage::disk('agenda_evidence')->delete($item->file_path)) {
                    throw new ConflictHttpException('File bukti gagal dihapus.');
                }
                $item->delete();
            }
            $metadata = ['agenda_id' => $agenda->id, 'title' => $agenda->title, 'owner_user_id' => $agenda->owner_user_id, 'branch_id' => $agenda->branch_id, 'scheduled_date' => $agenda->scheduled_date?->toDateString()];
            ActivityLog::create([
                'causer_id' => $actor->id,
                'subject_type' => ContentItem::class,
                'subject_id' => $agenda->id,
                'event' => 'sales_agenda_cleaned_by_superadmin',
                'description' => 'Agenda Sales dan bukti lokal dibersihkan oleh Superadmin.',
                'properties' => $metadata + ['evidence_count' => $evidence->count(), 'reason' => $reason, 'target_user_id' => $request->user()?->id, 'original_user_id' => $original?->id],
            ]);
            $agenda->delete();
        });
    }
}
