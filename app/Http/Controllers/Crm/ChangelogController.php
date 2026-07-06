<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreChangelogRequest;
use App\Http\Requests\Crm\UpdateChangelogRequest;
use App\Models\Changelog;
use Illuminate\Support\Facades\Auth;

class ChangelogController extends Controller
{
    public function index()
    {
        $changelogs = Changelog::with('creator')
            ->orderBy('created_at', 'desc')
            ->get();

        $grouped = $changelogs->groupBy(fn($item) => $item->created_at->format('d M Y'));

        return view('crm.changelog.index', compact('grouped'));
    }

    public function create()
    {
        return view('crm.changelog.create');
    }

    public function store(StoreChangelogRequest $request)
    {
        Changelog::create(array_merge($request->validated(), [
            'created_by' => Auth::id(),
        ]));

        return redirect()->route('changelogs.index')
            ->with('success', 'Changelog berhasil ditambahkan.');
    }

    public function edit(Changelog $changelog)
    {
        return view('crm.changelog.edit', compact('changelog'));
    }

    public function update(UpdateChangelogRequest $request, Changelog $changelog)
    {
        $changelog->update($request->validated());

        return redirect()->route('changelogs.index')
            ->with('success', 'Changelog berhasil diperbarui.');
    }

    public function destroy(Changelog $changelog)
    {
        $changelog->delete();

        return redirect()->route('changelogs.index')
            ->with('success', 'Changelog berhasil dihapus.');
    }
}
