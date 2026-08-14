<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StorePromoRequest;
use App\Http\Requests\Crm\UpdatePromoRequest;
use App\Models\ActivityLog;
use App\Models\Promo;
use App\Services\PromoAccessService;
use App\Services\PromoCodeGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PromoController extends Controller
{
    public function __construct(private PromoAccessService $access, private PromoCodeGenerator $codeGenerator) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Promo::class);
        $branches = $this->access->allowedBranches($request->user());
        $query = $this->access->visibleQuery($request->user())->with('branch');

        if ($request->filled('branch_id')) {
            abort_unless($request->user()->isSuperadmin() || $branches->contains('id', $request->integer('branch_id')), 403);
            $query->where('branch_id', $request->integer('branch_id'));
        }
        if (in_array($request->input('status'), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->input('status') === 'active');
        }
        if ($request->filled('search')) {
            $search = '%'.addcslashes(trim((string) $request->input('search')), '%_\\').'%';
            $query->where(fn ($builder) => $builder->where('name', 'like', $search)->orWhere('code', 'like', $search));
        }
        if ($request->input('validity') === 'current') {
            $query->where(fn ($builder) => $builder->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
                ->where(fn ($builder) => $builder->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
        } elseif ($request->input('validity') === 'upcoming') {
            $query->whereDate('start_date', '>', today());
        } elseif ($request->input('validity') === 'expired') {
            $query->whereDate('end_date', '<', today());
        }

        $promos = $query->orderBy('branch_id')->orderBy('name')->paginate(25)->withQueryString();

        return view('crm.promos.index', compact('promos', 'branches'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Promo::class);

        $branches = $this->access->allowedBranches($request->user());

        $branchLocked = ! $request->user()->isSuperadmin() && $branches->count() === 1;

        return view('crm.promos.create', [
            'branches' => $branches,
            'branchLocked' => $branchLocked,
            'selectedBranchId' => $branchLocked ? $branches->first()?->id : $request->integer('branch_id'),
        ]);
    }

    public function store(StorePromoRequest $request): RedirectResponse
    {
        $attributes = $request->safe()->except(['branch_id', 'code']);
        DB::transaction(function () use ($request, $attributes) {
            $promo = $this->codeGenerator->create($request->integer('branch_id'), $attributes, $request->user());
            $this->audit($request, $promo, 'promo_created', ['attributes' => $promo->only(['branch_id', 'code', 'name', 'start_date', 'end_date', 'description', 'is_active'])]);
        });

        return redirect()->route('promos.index')->with('success', 'Promo berhasil ditambahkan.');
    }

    public function edit(Request $request, Promo $promo): View
    {
        $this->authorize('update', $promo);

        return view('crm.promos.edit', ['promo' => $promo, 'branches' => $this->access->allowedBranches($request->user()), 'branchLocked' => ! $request->user()->isSuperadmin(), 'selectedBranchId' => $promo->branch_id]);
    }

    public function update(UpdatePromoRequest $request, Promo $promo): RedirectResponse
    {
        DB::transaction(function () use ($request, $promo) {
            $lockedPromo = Promo::query()->whereKey($promo->id)->lockForUpdate()->firstOrFail();
            $before = $lockedPromo->only(['branch_id', 'code', 'name', 'start_date', 'end_date', 'description', 'is_active']);
            $lockedPromo->update($request->safe()->except('code') + ['updated_by' => $request->user()->id]);
            $this->audit($request, $lockedPromo, 'promo_updated', ['before' => $before, 'after' => $lockedPromo->fresh()->only(array_keys($before))]);
        });

        return redirect()->route('promos.index')->with('success', 'Promo berhasil diperbarui.');
    }

    public function toggle(Request $request, Promo $promo): RedirectResponse
    {
        $this->authorize('toggle', $promo);
        DB::transaction(function () use ($request, $promo) {
            $before = $promo->is_active;
            $promo->update(['is_active' => ! $before, 'updated_by' => $request->user()->id]);
            $this->audit($request, $promo, 'promo_status_changed', ['before' => $before, 'after' => ! $before]);
        });

        return back()->with('success', 'Status promo berhasil diperbarui.');
    }

    private function audit(Request $request, Promo $promo, string $event, array $properties): void
    {
        ActivityLog::create(['causer_id' => $request->user()->id, 'subject_type' => Promo::class, 'subject_id' => $promo->id, 'event' => $event, 'description' => "Promo {$promo->name}: {$event}", 'properties' => $properties]);
    }
}
