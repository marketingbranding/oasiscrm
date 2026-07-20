<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\DanaTalanganSyncStatus;
use App\Models\DatabaseSheetRecord;
use App\Models\DatabaseSheetSyncStatus;
use App\Models\KonsumenProgressSyncStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiToolRegistry
{
    public function __construct(private readonly KonsumenPipelineService $pipelineService) {}

    public function allowedToolNames(): array
    {
        return ['count_by_stage', 'get_content_schedule', 'get_dana_talangan_summary', 'search_customer', 'get_today_summary', 'get_branch_info', 'get_supported_capabilities', 'ask_clarification'];
    }

    public function definitions(): array
    {
        return [
            $this->tool('count_by_stage', 'Hitung konsumen pada current stage canonical: BI Checking, PSJB, Pemberkasan, Proses Bank, PPJB Dev, Akad, atau BAST. Kirim cabang hanya melalui branch_id. Omit stage hanya jika user meminta semua stage.', [
                'stage' => ['type' => 'string', 'enum' => array_keys(KonsumenPipelineService::STAGES), 'description' => 'Satu nilai stage canonical saja, tanpa nama atau teks cabang.'],
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
        $explicitBranchId = $this->resolveBranchIdFromText($message, $user);
        $branchId = $user->canViewAllBranches()
            ? ($explicitBranchId ?? ($context['branch_id'] ?? null))
            : $user->branch_id;
        $explicitStage = $this->pipelineService->stageFromText($message);
        $mentionedBranch = $this->resolveMentionedBranch($message);
        $previousSearchQuery = trim((string) ($context['search_query'] ?? ''));
        $newSearchQuery = $this->extractCustomerSearchQuery($message);
        $searchCommand = preg_match('/\b(?:coba\s+cari|cari|search|cek\s+konsumen|ada\s+konsumen)\b/iu', $message) === 1;

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

        $customerSearchIntent = $newSearchQuery !== null || ($previousSearchQuery !== '' && $mentionedBranch) || $searchCommand;
        if ($customerSearchIntent && $mentionedBranch && ! $user->canViewAllBranches() && $mentionedBranch->id !== $user->branch_id) {
            return [[
                'name' => 'ask_clarification',
                'arguments' => ['message' => 'Pencarian tetap dibatasi ke cabang '.($user->branch?->name ?? 'akun Anda').' sesuai akses akun Anda.'],
            ]];
        }

        if ($newSearchQuery !== null) {
            return [[
                'name' => 'search_customer',
                'arguments' => [
                    'query' => $newSearchQuery,
                    'branch_id' => $branchId,
                ],
            ]];
        }

        if ($previousSearchQuery !== '' && $mentionedBranch) {
            return [[
                'name' => 'search_customer',
                'arguments' => [
                    'query' => $previousSearchQuery,
                    'branch_id' => $branchId,
                ],
            ]];
        }

        if ($searchCommand) {
            return [[
                'name' => 'ask_clarification',
                'arguments' => ['message' => 'Sebutkan nama konsumen yang ingin dicari.'],
            ]];
        }

        $allStagesRequested = Str::contains($lower, ['semua pipeline', 'semua stage', 'seluruh pipeline', 'seluruh stage']);
        $pipelineIntent = $explicitStage !== null
            || $allStagesRequested
            || Str::contains($lower, ['jumlah pipeline', 'berapa pipeline', 'jumlah konsumen', 'berapa konsumen', 'jumlahnya', 'konsumennya']);

        if ($pipelineIntent) {
            $contextStage = $this->pipelineService->canonicalStage($context['stage'] ?? null);
            $stage = $explicitStage ?? ($allStagesRequested ? null : $contextStage);

            if ($user->canViewAllBranches() && ! $branchId) {
                return [[
                    'name' => 'ask_clarification',
                    'arguments' => ['message' => 'Untuk superadmin atau pusat, sebutkan cabang dulu supaya saya tidak membaca semua cabang. Contoh: "jumlah BAST cabang Solo".'],
                ]];
            }

            $tool = [
                'name' => 'count_by_stage',
                'arguments' => [
                    'stage' => $stage,
                    'date_from' => Str::contains($lower, 'hari ini') ? $today->toDateString() : null,
                    'date_to' => Str::contains($lower, 'hari ini') ? $today->toDateString() : null,
                    'branch_id' => $branchId,
                ],
            ];
            $this->trace('local_parser', 'pipeline_count', $tool, $user);

            return [$tool];
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
        $branch = $this->resolveMentionedBranch($text);

        if (! $branch) {
            return null;
        }

        return $user->canViewAllBranches() ? $branch->id : $user->branch_id;
    }

    public function extractCustomerSearchQuery(string $message): ?string
    {
        if (preg_match('/\b(?:coba\s+cari|cari|search|cek\s+konsumen|ada\s+konsumen)\b/iu', $message) !== 1) {
            return null;
        }

        $query = preg_replace('/^.*?\b(?:coba\s+cari|cari|search|cek\s+konsumen|ada\s+konsumen)\b\s*/iu', '', trim($message));
        $query = preg_replace('/^(?:konsumen|customer)\b\s*/iu', '', (string) $query);
        $query = preg_replace('/^(?:bernama|atas\s+nama)\b\s*/iu', '', (string) $query);

        $branch = $this->resolveMentionedBranch($message);
        if ($branch) {
            $names = array_filter([$branch->name, $branch->code]);
            $branchPattern = implode('|', array_map(fn ($value) => preg_quote((string) $value, '/'), $names));
            $query = preg_replace('/(?:\s+(?:di|cabang|untuk(?:\s+cabang)?)\s+|^(?:di|cabang|untuk(?:\s+cabang)?)\s+)(?:'.$branchPattern.')[\s?!.,]*$/iu', '', (string) $query);
        }

        $query = trim((string) preg_replace('/\s+/', ' ', (string) $query), " \t\n\r\0\x0B?!.,");
        if ($query === '' || preg_match('/^(?:di|cabang|untuk|yang|kalau|sekarang)$/iu', $query) === 1) {
            return null;
        }

        if ($branch && in_array(Str::lower($query), [Str::lower($branch->name), Str::lower($branch->code), 'cabang '.Str::lower($branch->name), 'di '.Str::lower($branch->name)], true)) {
            return null;
        }

        return $query;
    }

    private function resolveMentionedBranch(string $text): ?Branch
    {
        $lower = Str::lower($text);
        $match = null;
        $lastPosition = -1;

        foreach (Branch::where('is_active', true)->get(['id', 'name', 'code']) as $branch) {
            foreach ([$branch->name, $branch->code] as $needle) {
                $position = mb_strripos($lower, Str::lower((string) $needle));
                if ($position !== false && $position >= $lastPosition) {
                    $match = $branch;
                    $lastPosition = $position;
                }
            }
        }

        return $match;
    }

    private function countByStage(array $arguments, User $user): array
    {
        $branchId = $this->allowedBranchId($arguments, $user);
        if (! $branchId) {
            return ['error' => 'Sebutkan cabang untuk menghitung Konsumen Progress.', 'source_module' => 'Konsumen Progress'];
        }

        $branch = Branch::find($branchId);
        if (! $branch) {
            return ['error' => 'Cabang tidak ditemukan.', 'source_module' => 'Konsumen Progress'];
        }

        $stageInput = trim((string) ($arguments['stage'] ?? ''));
        $stage = $this->pipelineService->canonicalStage($stageInput);
        if ($stageInput !== '' && ! $stage) {
            return [
                'error' => 'Stage "'.$stageInput.'" tidak dikenali. Stage yang tersedia: '.$this->humanList($this->pipelineService->validStageLabels()).'.',
                'received_stage' => $stageInput,
                'valid_stages' => array_keys($this->pipelineService->stages()),
                'source_module' => 'Konsumen Progress',
            ];
        }

        $this->trace('execution', 'pipeline_count', ['name' => 'count_by_stage', 'arguments' => ['stage' => $stage, 'branch_id' => $branchId]], $user);

        $dateFrom = $this->dateOrNull($arguments['date_from'] ?? null);
        $dateTo = $this->dateOrNull($arguments['date_to'] ?? null);
        $counts = $this->pipelineService->countByStage($branch, $stage, $dateFrom, $dateTo);

        $sync = $this->syncMeta('konsumen_progress', $branchId);

        return [
            'source_module' => 'Konsumen Progress',
            'branch_id' => $branchId,
            'branch' => $branch->name,
            'stage' => $stage ?: 'semua stage',
            'stage_label' => $stage ? $this->pipelineService->stages()[$stage] : 'Semua stage',
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo?->toDateString(),
            'count' => $counts['count'],
            'by_stage' => $counts['by_stage'],
        ] + $sync;
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
            'source_module' => 'Work Planner',
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
        $sync = $this->syncMeta('dana_talangan', null);

        return [
            'source_module' => 'Dana Talangan',
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
        ] + $sync;
    }

    private function searchCustomer(array $arguments, User $user): array
    {
        $branchId = $this->allowedBranchId($arguments, $user);
        $queryText = trim((string) ($arguments['query'] ?? ''));
        $term = Str::lower($queryText);

        if ($term === '') {
            return ['error' => 'Kata kunci pencarian kosong.'];
        }

        $branches = Branch::query()
            ->when($branchId, fn (Builder $query) => $query->whereKey($branchId))
            ->when(! $branchId && ! $user->canViewAllBranches(), fn (Builder $query) => $query->whereKey($user->branch_id))
            ->where('is_active', true)
            ->get(['id', 'name']);

        $pipeline = $branches
            ->flatMap(fn (Branch $branch) => $this->pipelineService->search($branch, $term, 10))
            ->take(10)
            ->map(fn (array $row) => [
                'source_module' => 'Konsumen Progress',
                'nama_konsumen' => $row['nama_konsumen'],
                'id_kavling' => $row['id_kavling'],
                'project_name' => $row['project_name'],
                'branch' => $row['branch'],
                'current_stage' => $row['current_stage'],
                'source_sheet' => $row['source_sheet'],
            ])
            ->values();

        $database = DatabaseSheetRecord::query()
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->whereNull('oasis_deleted_at')
            ->whereRaw('LOWER(row_data) LIKE ?', ['%'.$term.'%'])
            ->take(10)
            ->get()
            ->map(fn (DatabaseSheetRecord $row) => $this->serializeDatabaseRecord($row))
            ->values();

        $dana = DanaTalangan::query()
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->where('nama_konsumen', 'like', '%'.$term.'%')
            ->limit(10)
            ->with('branch:id,name')
            ->get(['id', 'branch_id', 'nama_konsumen', 'kav', 'project_name', 'status', 'tanggal'])
            ->map(fn (DanaTalangan $row) => [
                'source_module' => 'Dana Talangan',
                'nama_konsumen' => $row->nama_konsumen,
                'id_kavling' => $row->kav,
                'project_name' => $row->project_name,
                'branch' => $row->branch?->name,
                'status' => $row->status,
                'tanggal' => $row->tanggal?->toDateString(),
            ]);

        return [
            'source_module' => 'Customer Search',
            'branch_id' => $branchId,
            'branch' => $this->branchName($branchId),
            'query' => $queryText,
            'results' => $pipeline->concat($database)->concat($dana)->take(20)->values()->all(),
            'freshness' => [
                'database' => $this->syncMeta('database', $branchId),
                'konsumen_progress' => $this->syncMeta('konsumen_progress', $branchId ?: $user->branch_id),
                'dana_talangan' => $this->syncMeta('dana_talangan', null),
            ],
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
                'Konsumen Progress: current-stage BI Checking, PSJB, Pemberkasan, Proses Bank, PPJB Dev, Akad, dan BAST',
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

    private function serializeDatabaseRecord(DatabaseSheetRecord $row): array
    {
        $data = $row->row_data ?? [];

        return [
            'source_module' => 'Database',
            'source_sheet' => $row->sheet_name,
            'nama_konsumen' => $this->firstValue($data, ['nama_konsumen', 'nama konsumen', 'nama', 'customer']),
            'id_kavling' => $this->firstValue($data, ['id_kavling', 'id kavling', 'kavling', 'kav']),
            'project_name' => $this->firstValue($data, ['project_name', 'proyek', 'project']),
            'branch' => $row->branch?->name,
            'last_synced_at' => $row->last_synced_at?->toIso8601String(),
        ];
    }

    private function firstValue(array $data, array $keys): ?string
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $normalized[Str::lower(trim((string) $key))] = trim((string) $value);
            }
        }

        foreach ($keys as $key) {
            $value = $normalized[Str::lower($key)] ?? null;
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private function syncMeta(string $module, ?int $branchId): array
    {
        $staleAfter = (int) config('ai.sync_stale_minutes', 5);
        $status = match ($module) {
            'database' => $branchId && Schema::hasTable('database_sheet_sync_statuses') ? DatabaseSheetSyncStatus::where('branch_id', $branchId)->latest('finished_at')->first() : null,
            'konsumen_progress' => $branchId && Schema::hasTable('konsumen_progress_sync_statuses') ? KonsumenProgressSyncStatus::where('branch_id', $branchId)->latest('finished_at')->first() : null,
            'dana_talangan' => Schema::hasTable('dana_talangan_sync_statuses') ? DanaTalanganSyncStatus::latest('finished_at')->first() : null,
            default => null,
        };

        $lastSyncedAt = $status?->finished_at;

        return [
            'sync_status' => $status?->status ?? 'never_synced',
            'last_synced_at' => $lastSyncedAt?->toIso8601String(),
            'is_stale' => ! $lastSyncedAt || $status?->status !== 'success' || $lastSyncedAt->lt(now()->subMinutes($staleAfter)),
            'stale_after_minutes' => $staleAfter,
        ];
    }

    public function canonicalStage(?string $stage): ?string
    {
        return $this->pipelineService->canonicalStage($stage);
    }

    private function humanList(array $items): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).', dan '.$last;
    }

    private function trace(string $routeSource, string $intent, array $tool, User $user): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Log::debug('Oasis AI routing trace', [
            'routing_mode' => config('ai.routing_mode', 'hybrid'),
            'message_intent' => $intent,
            'tool_name' => $tool['name'] ?? null,
            'stage_argument' => $tool['arguments']['stage'] ?? null,
            'branch_id' => $user->canViewAllBranches() ? ($tool['arguments']['branch_id'] ?? null) : $user->branch_id,
            'route_source' => $routeSource,
            'synthesis_enabled' => (bool) config('ai.synthesize_tool_results', false),
        ]);
    }
}
