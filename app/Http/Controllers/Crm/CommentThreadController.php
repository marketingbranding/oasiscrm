<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Services\CommentableAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommentThreadController extends Controller
{
    public function __construct(private readonly CommentableAccessService $access) {}

    public function show(Request $request, string $alias, string $id)
    {
        validator(compact('alias', 'id'), [
            'alias' => ['required', Rule::in(['sales-lead', 'planner-item', 'sales-agenda', 'expense', 'bridge-fund'])],
            'id' => ['required', 'integer', 'min:1'],
        ])->validate();

        $target = $this->access->resolve($alias, $id);
        abort_unless($target, 404);
        $this->authorize('viewAny', Comment::class);
        abort_unless($this->access->canView($request->user(), $target), 403);

        $source = $this->access->sourceRoute($target);
        $context = match ($alias) {
            'sales-lead' => ['title' => 'Diskusi Lead Sales', 'module' => 'Buku Saku Sales', 'color' => '#fcc20f'],
            'sales-agenda' => ['title' => 'Diskusi Agenda Sales', 'module' => 'Buku Saku Sales', 'color' => '#fcc20f'],
            'planner-item' => ['title' => 'Diskusi Work Planner', 'module' => 'Work Planner', 'color' => '#b3bd95'],
            'expense' => ['title' => 'Diskusi Pengeluaran', 'module' => 'Pengeluaran', 'color' => '#b3bd95'],
            'bridge-fund' => ['title' => 'Diskusi Dana Talangan', 'module' => 'Dana Talangan', 'color' => '#f1c40f'],
        };

        return view('crm.comments.thread', [
            'targetLabel' => $this->access->label($target) ?: '#'.$target->getKey(),
            'targetAlias' => $alias,
            'targetId' => (int) $target->getKey(),
            'commentCount' => $target->comments()->count(),
            'backUrl' => $source ? route($source['name'], $source['parameters']) : route($request->user()->landingRouteName()),
            'moduleContext' => $context,
        ]);
    }
}
