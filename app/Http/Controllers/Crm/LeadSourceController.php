<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Crm\Traits\RedirectsShowToEdit;
use App\Models\LeadSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class LeadSourceController extends Controller
{
    use RedirectsShowToEdit;

    protected string $showEditRoute = 'lead-sources.edit';

    protected string $showEditParam = 'lead_source';

    private function resolveRedirect(Request $request): string
    {
        return $request->input('redirect_to', route('lead-sources.index'));
    }

    public function index()
    {
        $this->authorizeManagement();
        $sources = LeadSource::latest()->get();

        return view('crm.lead-sources.index', compact('sources'));
    }

    public function create()
    {
        $this->authorizeManagement();

        return view('crm.lead-sources.create');
    }

    public function store(Request $request)
    {
        $this->authorizeManagement();
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:lead_sources,name',
            'is_active' => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        $source = LeadSource::create($data);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'id' => $source->id, 'name' => $source->name]);
        }

        return redirect($this->resolveRedirect($request))
            ->with('success', 'Sumber lead berhasil ditambahkan.');
    }

    public function edit(LeadSource $leadSource)
    {
        $this->authorizeManagement();

        return view('crm.lead-sources.edit', compact('leadSource'));
    }

    public function update(Request $request, LeadSource $leadSource)
    {
        $this->authorizeManagement();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('lead_sources', 'name')->ignore($leadSource->id)],
            'is_active' => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        $leadSource->update($data);

        return redirect($this->resolveRedirect($request))
            ->with('success', 'Sumber lead berhasil diperbarui.');
    }

    public function destroy(Request $request, LeadSource $leadSource)
    {
        $this->authorizeManagement();
        $leadSource->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect($this->resolveRedirect($request))
            ->with('success', 'Sumber lead berhasil dihapus.');
    }

    public function bulkDestroy(Request $request)
    {
        $this->authorizeManagement();
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $count = LeadSource::whereIn('id', $ids)->delete();

        return redirect($this->resolveRedirect($request))
            ->with('success', "$count sumber lead berhasil dihapus.");
    }

    public function toggleActive(Request $request, LeadSource $leadSource)
    {
        $this->authorizeManagement();
        $leadSource->update([
            'is_active' => ! $leadSource->is_active,
        ]);

        return redirect($this->resolveRedirect($request))
            ->with('success', 'Status sumber lead berhasil diperbarui.');
    }

    private function authorizeManagement(): void
    {
        abort_unless(Auth::user()?->hasPermission('lead_sources.manage'), 403);
    }
}
