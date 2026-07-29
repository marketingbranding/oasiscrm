<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;

class NavigationService
{
    public function forUser(User $user, ?string $routeName = null): array
    {
        $routeName ??= request()->route()?->getName();
        $isSales = $user->isSales();

        return collect([
            $this->group('dashboard', 'Dashboard', 'dashboard', [
                ! $isSales ? $this->item('Dashboard', 'dashboard', 'dashboard', 'dashboard', ['dashboard'], $routeName) : null,
            ], direct: true),
            $this->group('activities', 'Aktivitas', 'calendar', [
                $user->hasScopedPermission('work_planner')
                    ? $this->item('Work Planner', 'content-calendar.index', 'calendar', 'planner', ['content-calendar.*'], $routeName)
                    : null,
            ]),
            $this->group('sales', 'Sales', 'sales', [
                $user->hasScopedPermission('sales_pocketbook')
                    ? $this->item('Buku Saku Sales', 'sales-pocketbook.index', 'sales', 'sales', ['sales-pocketbook.*', 'sales-leads.*', 'sales-agendas.*', 'sales-reminders.*'], $routeName)
                    : null,
                ! $isSales && $user->hasPermission('database.view') && $user->hasScopedPermission('database')
                    ? $this->item('Database', 'database.index', 'database', 'database', ['database.*'], $routeName)
                    : null,
                ! $isSales && $user->hasPermission('consumer_progress.view') && $user->hasScopedPermission('consumer_progress')
                    ? $this->item('Konsumen Progress', 'konsumen-progress.index', 'customers', 'consumer-progress', ['konsumen-progress.*'], $routeName)
                    : null,
            ]),
            $this->group('operations', 'Operasional', 'operations', [
                ! $isSales && $user->hasPermission('bridge_fund.view') && $user->hasScopedPermission('bridge_fund')
                    ? $this->item('Dana Talangan', 'dana-talangan.index', 'fund', 'bridge-fund', ['dana-talangan.*'], $routeName)
                    : null,
            ]),
            $this->group('finance', 'Keuangan', 'finance', [
                ! $isSales && $user->hasPermission('expenses.view') && $user->hasScopedPermission('expenses')
                    ? $this->item('Pengeluaran', 'expenses.index', 'finance', 'expenses', ['expenses.*'], $routeName)
                    : null,
                ! $isSales && $user->hasPermission('expenses.view') && $user->hasScopedPermission('expenses') && $user->hasPermission('expenses.manage_categories')
                    ? $this->item('Kategori Pengeluaran', 'expense-categories.index', 'category', 'expenses', ['expense-categories.*'], $routeName)
                    : null,
            ]),
            $this->group('reports', 'Laporan', 'reports', [
                ! $isSales && ($user->isSuperadmin() || $user->hasPrimaryRole('pusat'))
                    ? $this->item('Review Laporan', 'feedback-reports.index', 'report', 'reports', ['feedback-reports.index', 'feedback-reports.show', 'feedback-reports.review'], $routeName)
                    : null,
            ]),
            $this->group('administration', 'Administrasi', 'administration', [
                ! $isSales && $user->isSuperadmin() && $user->hasPermission('branches.manage')
                    ? $this->item('Cabang', 'branches.index', 'branch', 'administration', ['branches.*'], $routeName)
                    : null,
                ! $isSales && $user->isSuperadmin() && $user->hasPermission('projects.manage')
                    ? $this->item('Proyek', 'projects.index', 'project', 'administration', ['projects.*', 'kavlings.*'], $routeName)
                    : null,
                ! $isSales && $user->hasPermission('users.view')
                    ? $this->item('User', 'admin-users.index', 'users', 'administration', ['admin-users.*'], $routeName)
                    : null,
                ! $isSales && $user->hasPermission('system_health.view')
                    ? $this->item('System Health', 'admin.system-health', 'health', 'administration', ['admin.system-health'], $routeName)
                    : null,
                ! $isSales && $user->can('viewDesignSystem')
                    ? $this->item('Design System', 'admin.design-system', 'administration', 'administration', ['admin.design-system'], $routeName)
                    : null,
                ! $isSales
                    ? $this->item('Changelog', 'changelogs.index', 'changelog', 'administration', ['changelogs.*'], $routeName)
                    : null,
            ]),
        ])->filter()->values()->all();
    }

    private function group(string $key, string $label, string $icon, array $items, bool $direct = false): ?array
    {
        $children = collect($items)->filter()->values()->all();

        if ($children === []) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'direct' => $direct && count($children) === 1,
            'active' => collect($children)->contains('active', true),
            'children' => $children,
        ];
    }

    private function item(
        string $label,
        string $route,
        string $icon,
        string $accent,
        array $activePatterns,
        ?string $currentRoute,
    ): ?array {
        if (! Route::has($route)) {
            if (app()->isProduction()) {
                throw new LogicException("Expected navigation route [{$route}] is not registered.");
            }

            return null;
        }

        return [
            'label' => $label,
            'route' => $route,
            'icon' => $icon,
            'accent' => $accent,
            'active_patterns' => $activePatterns,
            'active' => $currentRoute !== null && Str::is($activePatterns, $currentRoute),
        ];
    }
}
