<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreExpenseCategoryRequest;
use App\Http\Requests\Crm\UpdateExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ExpenseCategoryController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', ExpenseCategory::class);

        $categories = ExpenseCategory::query()->orderBy('sort_order')->orderBy('name')->get();

        return view('crm.expense-categories.index', compact('categories'));
    }

    public function store(StoreExpenseCategoryRequest $request): RedirectResponse
    {
        ExpenseCategory::create($request->validated() + [
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Kategori pengeluaran berhasil ditambahkan.');
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $expenseCategory->update($request->validated() + [
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Kategori pengeluaran berhasil diperbarui.');
    }

    public function toggle(ExpenseCategory $expenseCategory): RedirectResponse
    {
        $this->authorize('update', $expenseCategory);

        $expenseCategory->update([
            'is_active' => ! $expenseCategory->is_active,
            'updated_by' => request()->user()->id,
        ]);

        return back()->with('success', 'Status kategori pengeluaran berhasil diperbarui.');
    }
}
