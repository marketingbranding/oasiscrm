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
    public function index()
    {
        $sources = LeadSource::latest()->get();
        return view('crm.lead-sources.index', compact('sources'));
    }

    public function create()
    {
        return view('crm.lead-sources.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:lead_sources,name',
            'is_active' => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        LeadSource::create($data);

        return redirect()->route('lead-sources.index')
            ->with('success', 'Sumber lead berhasil ditambahkan.');
    }

    public function edit(LeadSource $leadSource)
    {
        return view('crm.lead-sources.edit', compact('leadSource'));
    }

    public function update(Request $request, LeadSource $leadSource)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('lead_sources', 'name')->ignore($leadSource->id)],
            'is_active' => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        $leadSource->update($data);

        return redirect()->route('lead-sources.index')
            ->with('success', 'Sumber lead berhasil diperbarui.');
    }

    public function destroy(LeadSource $leadSource)
    {
        $leadSource->delete();

        return redirect()->route('lead-sources.index')
            ->with('success', 'Sumber lead berhasil dihapus.');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = array_filter(explode(',', $request->input('selected_ids', '')));
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih.');
        }

        $count = LeadSource::whereIn('id', $ids)->delete();

        return redirect()->route('lead-sources.index')
            ->with('success', "$count sumber lead berhasil dihapus.");
    }

    public function toggleActive(LeadSource $leadSource)
    {
        $leadSource->update([
            'is_active' => !$leadSource->is_active,
        ]);

        return redirect()->route('lead-sources.index')
            ->with('success', 'Status sumber lead berhasil diperbarui.');
    }
}
