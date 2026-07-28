<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CancelExpenseRequest;
use App\Http\Requests\Crm\StoreExpenseRequest;
use App\Http\Requests\Crm\UpdateExpenseRequest;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LeadMaster;
use App\Services\OptimisticLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ExpenseController extends Controller
{
    public function __construct(private readonly OptimisticLockService $optimisticLock) {}

    public function index()
    {
        $this->authorize('viewAny', Expense::class);
        $expenses = Expense::with(['branch', 'project', 'category', 'creator'])
            ->orderByDesc('expense_date')->orderByDesc('id')->paginate(20);

        return view('crm.expenses.index', compact('expenses'));
    }

    public function create()
    {
        $this->authorize('create', Expense::class);
        $defaultBranch = request()->user()->hasRole('pusat')
            ? Branch::where('is_active', true)->whereRaw('UPPER(code) = ?', ['PST'])->first()
            : null;

        return view('crm.expenses.create', $this->formData(null, old('branch_id', $defaultBranch?->id)));
    }

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $this->authorize('create', Expense::class);
        $data = Arr::except($request->validated(), 'submit_action');
        $data += [
            'status' => Expense::STATUS_ACTIVE,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ];
        $expense = Expense::create($data);

        if ($request->input('submit_action') === 'add_another') {
            return redirect()->route('expenses.create')->withInput(Arr::only($data, [
                'expense_date', 'branch_id', 'project_id', 'expense_category_id', 'payment_method',
            ]))->with('success', 'Pengeluaran berhasil disimpan. Silakan tambahkan pengeluaran berikutnya.');
        }

        return redirect()->route('expenses.show', $expense)->with('success', 'Pengeluaran berhasil disimpan.');
    }

    public function show(Expense $expense)
    {
        $this->authorize('view', $expense);
        $expense->load(['branch', 'project', 'category', 'creator', 'updatedBy', 'cancelledBy', 'activities.causer']);

        return view('crm.expenses.show', compact('expense'));
    }

    public function edit(Expense $expense)
    {
        $this->authorize('update', $expense);
        abort_if($expense->status === Expense::STATUS_CANCELLED, 422, 'Pengeluaran yang sudah dibatalkan tidak dapat diubah.');
        $expense->load(['branch', 'project', 'category']);

        return view('crm.expenses.edit', $this->formData($expense, old('branch_id', $expense->branch_id)));
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): Response
    {
        $this->authorize('update', $expense);
        $data = Arr::except($request->validated(), 'expected_updated_at');
        $result = $this->optimisticLock->execute($request, $expense, $request->input('expected_updated_at'), function (Expense $current) use ($data, $request) {
            $this->authorize('update', $current);
            abort_if($current->status === Expense::STATUS_CANCELLED, 422, 'Pengeluaran yang sudah dibatalkan tidak dapat diubah.');
            $current->update($data + ['updated_by' => $request->user()->id]);

            return $current;
        });
        if ($result instanceof Response) {
            return $result;
        }

        return redirect()->route('expenses.show', $result)->with('success', 'Pengeluaran berhasil diperbarui.');
    }

    public function cancel(CancelExpenseRequest $request, Expense $expense): Response
    {
        $this->authorize('cancel', $expense);
        $result = $this->optimisticLock->execute($request, $expense, $request->input('expected_updated_at'), function (Expense $current) use ($request) {
            $this->authorize('cancel', $current);
            abort_if($current->status === Expense::STATUS_CANCELLED, 422, 'Pengeluaran ini sudah dibatalkan.');
            $current->update([
                'status' => Expense::STATUS_CANCELLED,
                'cancellation_reason' => $request->validated('cancellation_reason'),
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            $current->logActivity('cancelled', ['reason' => $request->validated('cancellation_reason')]);

            return $current;
        });
        if ($result instanceof Response) {
            return $result;
        }

        return redirect()->route('expenses.show', $result)->with('success', 'Pengeluaran berhasil dibatalkan.');
    }

    public function projects(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);
        $data = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)],
        ]);

        try {
            $projects = LeadMaster::where('branch_id', $data['branch_id'])->where('is_active', true)
                ->orderBy('project_name')->get(['id', 'project_name'])
                ->map(fn (LeadMaster $project) => ['id' => $project->id, 'name' => $project->project_name]);

            return response()->json(['projects' => $projects]);
        } catch (Throwable $exception) {
            Log::error('Gagal memuat pilihan proyek pengeluaran.', ['exception' => $exception]);

            return response()->json([
                'message' => 'Pilihan proyek gagal dimuat. Silakan coba lagi.',
                'retryable' => true,
            ], 500);
        }
    }

    private function formData(?Expense $expense, mixed $branchId): array
    {
        $branches = Branch::where('is_active', true)->forDropdown()->get(['id', 'name', 'code']);
        $categories = ExpenseCategory::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        if ($expense && ! $categories->contains('id', $expense->expense_category_id)) {
            $categories->push($expense->category);
        }
        $projects = filled($branchId)
            ? LeadMaster::where('branch_id', $branchId)->where('is_active', true)->orderBy('project_name')->get(['id', 'project_name'])
            : collect();

        return compact('expense', 'branches', 'categories', 'projects') + [
            'paymentMethods' => Expense::PAYMENT_METHODS,
            'optimisticToken' => $expense ? $this->optimisticLock->token($expense) : null,
            'initialBranchId' => (string) ($branchId ?? ''),
        ];
    }
}
