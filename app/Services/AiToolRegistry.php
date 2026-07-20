<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\DatabaseSheetRecord;
use App\Models\KonsumenProgressSheetRow;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AiToolRegistry
{
    public function definitions(): array
    {
        return [
            $this->tool('count_by_stage', 'Hitung jumlah konsumen di stage pipeline tertentu, misalnya akad, booking, sp3k, atau semua stage.', [
                'stage' => ['type' => 'string', 'description' => 'Nama stage/sheet, contoh: akad. Opsional.'],
                'date_from' => ['type' => 'string', 'description' => 'Tanggal awal YYYY-MM-DD. Opsional.'],
                'date_to' => ['type' => 'string', 'description' => 'Tanggal akhir YYYY-MM-DD. Opsional.'],
                'branch_id' => ['type' => 'integer', 'description' => 'ID cabang. Hanya berlaku untuk superadmin/pusat.'],
            ]),
            $this->tool('get_content_schedule', 'Ambil jadwal Work Planner, terutama konten/tugas/agenda dalam rentang tanggal.', [
                'start_date' => ['type' => 'string', 'description' => 'Tanggal awal YYYY-MM-DD.'],
                'end_date' => ['type' => 'string', 'description' => 'Tanggal akhir YYYY-MM-DD.'],
                'item_type' => ['type' => 'string', 'enum' => ['task', 'agenda', 'content'], 'description' => 'Jenis item. Opsional.'],
                'branch_id' => ['type' => 'integer', 'description' => 'ID cabang. Hanya berlaku untuk superadmin/pusat.'],
            ]),
            $this->tool('get_dana_talangan_summary', 'Ringkas data Dana Talangan berdasarkan status, tanggal, dan cabang.', [
                'status' => ['type' => 'string', 'description' => 'Status dana talangan. Opsional.'],
                'query' => ['type' => 'string', 'description' => 'Nama konsumen atau kata kunci. Opsional.'],
                'date_from' => ['type' => 'string', 'description' => 'Tanggal awal YYYY-MM-DD. Opsional.'],
                'date_to' => ['type' => 'string', 'description' => 'Tanggal akhir YYYY-MM-DD. Opsional.'],
                'branch_id' => ['type' => 'integer', 'description' => 'ID cabang. Hanya berlaku untuk superadmin/pusat.'],
            ]),
            $this->tool('search_customer', 'Cari konsumen/customer berdasarkan nama di pipeline, database sheet, dan Dana Talangan.', [
                'query' => ['type' => 'string', 'description' => 'Nama konsumen atau kata kunci.'],
                'branch_id' => ['type' => 'integer', 'description' => 'ID cabang. Hanya berlaku untuk superadmin/pusat.'],
            ], ['query']),
            $this->tool('get_today_summary', 'Ambil ringkasan aktivitas hari ini dari Work Planner, Dana Talangan, dan pipeline.', [
                'branch_id' => ['type' => 'integer', 'description' => 'ID cabang. Hanya berlaku untuk superadmin/pusat.'],
            ]),
            $this->tool('get_branch_info', 'Ambil informasi cabang yang sedang digunakan.', [
                'branch_id' => ['type' => 'integer', 'description' => 'ID cabang. Hanya berlaku untuk superadmin/pusat.'],
            ]),
            $this->tool('get_supported_capabilities', 'Jelaskan data Oasis apa saja yang bisa dibaca oleh AI chat.', []),
        ];
    }

    public function execute(string $name, array $arguments, User $user): array
    {
        return match ($name) {
            'count_by_stage' => $this->countByStage($arguments, $user),
            'get_content_schedule' => $this->contentSchedule($arguments, $user),
            'get_dana_talangan_summary' => $this->danaTalanganSummary($arguments, $user),
            'search_customer' => $this->searchCustomer($arguments, $user),
            'get_today_summary' => $this->todaySummary($arguments, $user),
            'get_branch_info' => $this->branchInfo($arguments, $user),
            'get_supported_capabilities' => $this->supportedCapabilities(),
            'ask_clarification' => ['message' => $arguments['message'] ?? 'Sebutkan cabang dan data yang ingin dicek.'],
            default => ['error' => 'Tool tidak dikenal.'],
        };
    }

    public function inferTools(string $message, User $user, array $context = []): array
    {
        $lower = Str::lower($message);
        $today = today();
        $branchId = $this->resolveBranchIdFromText($message, $user) ?? ($context['branch_id'] ?? null);

        if (Str::contains($lower, ['data apa', 'bisa cari', 'bisa kamu cari', 'bisa kamu baca', 'kemampuan', 'capability'])) {
            return [['name' => 'get_supported_capabilities', 'arguments' => []]];
        }

        if (Str::contains($lower, ['berapa cabang', 'jumlah cabang', 'daftar cabang', 'cabang kita'])) {
            return [['name' => 'get_branch_info', 'arguments' => []]];
        }

        if (Str::contains($lower, ['konten', 'content', 'jadwal'])) {
            $start = Str::contains($lower, ['minggu ini', 'pekan ini']) ? $today->copy()->startOfWeek() : $today;
            $end = Str::contains($lower, ['minggu ini', 'pekan ini']) ? $today->copy()->endOfWeek() : $today;

            return [[
                'name' => 'get_content_schedule',
                'arguments' => [
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'item_type' => Str::contains($lower, ['konten', 'content']) ? 'content' : null,
                    'branch_id' => $branchId,
                ],
            ]];
        }

        if (Str::contains($lower, ['cari customer', 'cari konsumen', 'search customer', 'search konsumen', 'coba cari'])) {
            $query = preg_replace('/\b(coba cari|cari|search)\s+(customer|konsumen)?\b/i', '', $message);
            $query = preg_replace('/\b(bernama|atas nama)\b/i', '', (string) $query);

            return [[
                'name' => 'search_customer',
                'arguments' => [
                    'query' => trim((string) $query),
                    'branch_id' => $branchId,
                ],
            ]];
        }

        if (! empty($context['search_query']) && $branchId && Str::contains($lower, ['bukan', 'cabang', 'di ', 'untuk'])) {
            return [[
                'name' => 'search_customer',
                'arguments' => [
                    'query' => $context['search_query'],
                    'branch_id' => $branchId,
                ],
            ]];
        }

        if (Str::contains($lower, ['akad', 'booking', 'sp3k', 'bast', 'pipeline', 'konsumen', 'konsumennya', 'pemberkasan', 'wawancara', 'realisasi', 'jumlahnya'])) {
            preg_match('/(akad|booking|sp3k|bast|pemberkasan|wawancara|realisasi|pipeline|konsumen)/i', $message, $match);
            $stage = Str::lower($match[1] ?? ($context['stage'] ?? ''));
            if (in_array($stage, ['pipeline', 'konsumen', ''], true) && ! empty($context['stage'])) {
                $stage = Str::lower((string) $context['stage']);
            }

            if ($user->canViewAllBranches() && ! $branchId) {
                return [[
                    'name' => 'ask_clarification',
                    'arguments' => ['message' => 'Untuk superadmin atau pusat, sebutkan cabang dulu supaya saya tidak membaca semua cabang. Contoh: "jumlah BAST cabang Solo".'],
                ]];
            }

            return [[
                'name' => 'count_by_stage',
                'arguments' => [
                    'stage' => ! in_array($stage, ['pipeline', 'konsumen', ''], true) ? $stage : null,
                    'date_from' => Str::contains($lower, 'hari ini') ? $today->toDateString() : null,
                    'date_to' => Str::contains($lower, 'hari ini') ? $today->toDateString() : null,
                    'branch_id' => $branchId,
                ],
            ]];
        }

        if (Str::contains($lower, ['dana talangan', 'talangan', 'lunas'])) {
            preg_match('/atas nama\s+(.+)/i', $message, $nameMatch);

            return [['name' => 'get_dana_talangan_summary', 'arguments' => [
                'branch_id' => $branchId,
                'status' => Str::contains($lower, 'lunas') ? 'lunas' : null,
                'query' => isset($nameMatch[1]) ? trim($nameMatch[1]) : null,
            ]]];
        }

        if (Str::contains($lower, ['hari ini', 'summary', 'ringkasan'])) {
            return [['name' => 'get_today_summary', 'arguments' => []]];
        }

        if (! empty($context['stage']) && $branchId && Str::contains($lower, ['cabang', 'bukan semua', 'untuk'])) {
            return [[
                'name' => 'count_by_stage',
                'arguments' => [
                    'stage' => $context['stage'],
                    'branch_id' => $branchId,
                ],
            ]];
        }

        return [];
    }

    private function tool(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                ],
            ],
        ];
    }

    private function allowedBranchId(array $arguments, User $user): ?int
    {
        if ($user->canViewAllBranches()) {
            return filled($arguments['branch_id'] ?? null) ? (int) $arguments['branch_id'] : null;
        }

        return $user->branch_id;
    }

    private function branchName(?int $branchId): string
    {
        if (! $branchId) {
            return 'Semua cabang';
        }

        return Branch::find($branchId)?->name ?? 'Cabang tidak diketahui';
    }

    private function resolveBranchIdFromText(string $text, User $user): ?int
    {
        $lower = Str::lower($text);
        $branch = Branch::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'code'])
            ->first(fn (Branch $branch) => Str::contains($lower, Str::lower($branch->name)) || Str::contains($lower, Str::lower($branch->code)));

        if (! $branch) {
            return null;
        }

        return $user->canViewAllBranches() ? $branch->id : $user->branch_id;
    }

    private function countByStage(array $arguments, User $user): array
    {
        $branchId = $this->allowedBranchId($arguments, $user);
        $stage = trim((string) ($arguments['stage'] ?? ''));
        $dateFrom = $this->dateOrNull($arguments['date_from'] ?? null);
        $dateTo = $this->dateOrNull($arguments['date_to'] ?? null);

        $query = KonsumenProgressSheetRow::query()
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($stage !== '', fn (Builder $query) => $query->where('sheet_name', 'like', '%'.$stage.'%'));

        $rows = ($dateFrom || $dateTo) ? $query->get()->filter(fn ($row) => $this->rowHasDateBetween($row->row_data ?? [], $dateFrom, $dateTo)) : $query->get();

        return [
            'branch' => $this->branchName($branchId),
            'stage' => $stage ?: 'semua stage',
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo?->toDateString(),
            'count' => $rows->count(),
            'by_stage' => $rows->groupBy('sheet_name')->map->count()->all(),
        ];
    }

    private function contentSchedule(array $arguments, User $user): array
    {
        $branchId = $this->allowedBranchId($arguments, $user);
        $start = $this->dateOrNull($arguments['start_date'] ?? null) ?? today();
        $end = $this->dateOrNull($arguments['end_date'] ?? null) ?? $start->copy();
        $type = in_array($arguments['item_type'] ?? null, ContentItem::TYPES, true) ? $arguments['item_type'] : null;

        $items = ContentItem::query()
            ->visibleTo($user)
            ->with(['branch:id,name'])
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($type, fn (Builder $query) => $query->where('item_type', $type))
            ->whereDate('scheduled_date', '>=', $start->toDateString())
            ->whereDate('scheduled_date', '<=', $end->toDateString())
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->limit(30)
            ->get();

        return [
            'branch' => $this->branchName($branchId ?: ($user->canViewAllBranches() ? null : $user->branch_id)),
            'date_from' => $start->toDateString(),
            'date_to' => $end->toDateString(),
            'item_type' => $type ?: 'semua',
            'count' => $items->count(),
            'items' => $items->map(fn (ContentItem $item) => [
                'date' => $item->scheduled_date?->toDateString(),
                'time' => $item->start_time ? substr($item->start_time, 0, 5) : null,
                'title' => $item->title,
                'status' => $item->status,
                'type' => $item->item_type,
                'branch' => $item->branch?->name,
            ])->all(),
        ];
    }

    private function danaTalanganSummary(array $arguments, User $user): array
    {
        $branchId = $this->allowedBranchId($arguments, $user);
        $dateFrom = $this->dateOrNull($arguments['date_from'] ?? null);
        $dateTo = $this->dateOrNull($arguments['date_to'] ?? null);
        $status = trim((string) ($arguments['status'] ?? ''));
        $term = trim((string) ($arguments['query'] ?? ''));

        $query = DanaTalangan::query()
            ->with(['branch:id,name'])
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($term) {
                $query->where('nama_konsumen', 'like', '%'.$term.'%')
                    ->orWhere('kav', 'like', '%'.$term.'%')
                    ->orWhere('project_name', 'like', '%'.$term.'%');
            }))
            ->when($dateFrom, fn (Builder $query) => $query->whereDate('tanggal', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query) => $query->whereDate('tanggal', '<=', $dateTo));

        $records = (clone $query)->latest('tanggal')->limit(10)->get();

        return [
            'branch' => $this->branchName($branchId),
            'count' => (clone $query)->count(),
            'needs_confirmation' => (clone $query)->where('konfirmasi_keuangan', false)->count(),
            'by_status' => (clone $query)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'records' => $records->map(fn (DanaTalangan $row) => [
                'nama_konsumen' => $row->nama_konsumen,
                'kav' => $row->kav,
                'project_name' => $row->project_name,
                'status' => $row->status,
                'tanggal' => $row->tanggal?->toDateString(),
                'tgl_komitmen' => $row->tgl_komitmen?->toDateString(),
                'konfirmasi_keuangan' => $row->konfirmasi_keuangan,
                'branch' => $row->branch?->name,
            ])->all(),
        ];
    }

    private function searchCustomer(array $arguments, User $user): array
    {
        $branchId = $this->allowedBranchId($arguments, $user);
        $term = Str::lower(trim((string) ($arguments['query'] ?? '')));

        if ($term === '') {
            return ['error' => 'Kata kunci pencarian kosong.'];
        }

        $pipeline = KonsumenProgressSheetRow::query()
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->limit(300)
            ->get()
            ->filter(fn ($row) => Str::contains(Str::lower(json_encode($row->row_data ?? [])), $term))
            ->take(10)
            ->map(fn ($row) => ['source' => 'Konsumen Progress', 'stage' => $row->sheet_name, 'data' => array_slice($row->row_data ?? [], 0, 6, true)])
            ->values();

        $database = DatabaseSheetRecord::query()
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->whereNull('oasis_deleted_at')
            ->limit(300)
            ->get()
            ->filter(fn ($row) => Str::contains(Str::lower(json_encode($row->row_data ?? [])), $term))
            ->take(10)
            ->map(fn ($row) => ['source' => 'Database', 'sheet' => $row->sheet_name, 'data' => array_slice($row->row_data ?? [], 0, 6, true)])
            ->values();

        $dana = DanaTalangan::query()
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->where('nama_konsumen', 'like', '%'.$term.'%')
            ->limit(10)
            ->get(['nama_konsumen', 'kav', 'project_name', 'status', 'tanggal'])
            ->map(fn ($row) => ['source' => 'Dana Talangan', 'data' => $row->toArray()]);

        return [
            'branch' => $this->branchName($branchId),
            'query' => $term,
            'results' => $pipeline->concat($database)->concat($dana)->values()->all(),
        ];
    }

    private function todaySummary(array $arguments, User $user): array
    {
        $branchId = $this->allowedBranchId($arguments, $user);
        $today = today()->toDateString();

        return [
            'branch' => $this->branchName($branchId ?: ($user->canViewAllBranches() ? null : $user->branch_id)),
            'date' => $today,
            'work_planner' => $this->contentSchedule(['start_date' => $today, 'end_date' => $today, 'branch_id' => $branchId], $user),
            'dana_talangan' => $this->danaTalanganSummary(['date_from' => $today, 'date_to' => $today, 'branch_id' => $branchId], $user),
            'pipeline' => $this->countByStage(['date_from' => $today, 'date_to' => $today, 'branch_id' => $branchId], $user),
        ];
    }

    private function branchInfo(array $arguments, User $user): array
    {
        $branchId = $this->allowedBranchId($arguments, $user);
        $branches = Branch::query()
            ->when($branchId, fn (Builder $query) => $query->where('id', $branchId))
            ->when(! $branchId && ! $user->canViewAllBranches(), fn (Builder $query) => $query->where('id', $user->branch_id))
            ->get(['id', 'name', 'code', 'phone', 'email', 'is_active']);

        return ['branches' => $branches->toArray()];
    }

    private function supportedCapabilities(): array
    {
        return [
            'capabilities' => [
                'Daftar dan jumlah cabang',
                'Dana Talangan: jumlah, status, nama konsumen, kav, proyek, tanggal, dan konfirmasi keuangan',
                'Work Planner: jadwal task, agenda, dan konten berdasarkan tanggal/cabang',
                'Konsumen Progress: jumlah per stage seperti akad, booking, SP3K, BAST, pemberkasan, wawancara, realisasi',
                'Database sheet cache: pencarian kata kunci pada data hasil sync Google Sheets',
                'Pencarian nama konsumen lintas Dana Talangan, Konsumen Progress, dan Database',
            ],
            'limitations' => [
                'Saya hanya boleh membaca data yang tersedia di cache/database Oasis.',
                'Saya tidak boleh mengarang angka, status, nama, atau alasan jika data tidak ada.',
                'Akses cabang mengikuti role user login.',
            ],
        ];
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function rowHasDateBetween(array $rowData, ?Carbon $from, ?Carbon $to): bool
    {
        foreach ($rowData as $value) {
            if (blank($value) || ! is_scalar($value)) {
                continue;
            }

            try {
                $date = Carbon::parse((string) $value)->startOfDay();
            } catch (\Throwable) {
                continue;
            }

            if ((! $from || $date->gte($from)) && (! $to || $date->lte($to))) {
                return true;
            }
        }

        return false;
    }
}
