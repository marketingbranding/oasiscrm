<?php

namespace App\Services;

use App\Models\Promo;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;

class NavigationService
{
    public function forUser(User $user, ?string $routeName = null, array $moduleMaintenance = []): array
    {
        $routeName ??= request()->route()?->getName();
        $isSales = $user->isSales();

        return collect([
            $this->group('dashboard', 'Dashboard', 'dashboard', [
                ! $isSales ? $this->item('Dashboard', 'dashboard', 'dashboard', 'dashboard', ['dashboard'], $routeName, null, $moduleMaintenance) : null,
            ], direct: true),
            $this->group('activities', 'Aktivitas', 'calendar', [
                $user->hasScopedPermission('work_planner')
                    ? $this->item('Work Planner', 'content-calendar.index', 'calendar', 'planner', ['content-calendar.*'], $routeName, 'work_planner', $moduleMaintenance)
                    : null,
            ]),
            $this->group('sales', 'Sales', 'sales', [
                $user->hasScopedPermission('sales_pocketbook')
                    ? $this->item('Buku Saku Sales', 'sales-pocketbook.index', 'sales', 'sales', ['sales-pocketbook.*', 'sales-leads.*', 'sales-agendas.*', 'sales-reminders.*'], $routeName, 'sales_pocketbook', $moduleMaintenance)
                    : null,
                ! $isSales && $user->hasPermission('database.view') && $user->hasScopedPermission('database')
                    ? $this->item('Database', 'database.index', 'database', 'database', ['database.*'], $routeName, 'database', $moduleMaintenance)
                    : null,
                ! $isSales && $user->hasPermission('consumer_progress.view') && $user->hasScopedPermission('consumer_progress')
                    ? $this->item('Konsumen Progress', 'konsumen-progress.index', 'customers', 'consumer-progress', ['konsumen-progress.*'], $routeName, 'consumer_progress', $moduleMaintenance)
                    : null,
            ]),
            $this->group('operations', 'Operasional', 'operations', [
                ! $isSales && $user->hasPermission('bridge_fund.view') && $user->hasScopedPermission('bridge_fund')
                    ? $this->item('Dana Talangan', 'dana-talangan.index', 'fund', 'bridge-fund', ['dana-talangan.*'], $routeName, 'dana_talangan', $moduleMaintenance)
                    : null,
            ]),
            $this->group('finance', 'Keuangan', 'finance', [
                ! $isSales && $user->hasPermission('expenses.view') && $user->hasScopedPermission('expenses')
                    ? $this->item('Pengeluaran', 'expenses.index', 'finance', 'expenses', ['expenses.*'], $routeName, null, $moduleMaintenance)
                    : null,
                ! $isSales && $user->hasPermission('expenses.view') && $user->hasScopedPermission('expenses') && $user->hasPermission('expenses.manage_categories')
                    ? $this->item('Kategori Pengeluaran', 'expense-categories.index', 'category', 'expenses', ['expense-categories.*'], $routeName, null, $moduleMaintenance)
                    : null,
            ]),
            $this->group('reports', 'Laporan', 'reports', [
                ! $isSales && ($user->isSuperadmin() || $user->hasPrimaryRole('pusat'))
                    ? $this->item('Review Laporan', 'feedback-reports.index', 'report', 'reports', ['feedback-reports.index', 'feedback-reports.show', 'feedback-reports.review'], $routeName, 'feedback_reports', $moduleMaintenance)
                    : null,
                $user->hasPrimaryRole('admin') && $user->hasScopedPermission('sales_pocketbook')
                    ? $this->item('Laporan Fee Sales', 'sales-fee-reports.index', 'report', 'reports', ['sales-fee-reports.*'], $routeName, null, $moduleMaintenance)
                    : null,
            ]),
            $this->group('administration', 'Administrasi', 'administration', [
                ! $isSales && $user->can('viewAny', Promo::class)
                    ? $this->item('Promo', 'promos.index', 'sales', 'administration', ['promos.*'], $routeName, 'promo', $moduleMaintenance)
                    : null,
                ! $isSales && $user->isSuperadmin() && $user->hasPermission('branches.manage')
                    ? $this->item('Cabang', 'branches.index', 'branch', 'administration', ['branches.*'], $routeName, 'branches', $moduleMaintenance)
                    : null,
                ! $isSales && $user->isSuperadmin() && $user->hasPermission('projects.manage')
                    ? $this->item('Proyek', 'projects.index', 'project', 'administration', ['projects.*', 'kavlings.*'], $routeName, ['projects', 'kavling'], $moduleMaintenance)
                    : null,
                ! $isSales && $user->hasPermission('users.view')
                    ? $this->item('User', 'admin-users.index', 'users', 'administration', ['admin-users.*'], $routeName, 'users', $moduleMaintenance)
                    : null,
                ! $isSales && $user->hasPermission('system_health.view')
                    ? $this->item('System Health', 'admin.system-health', 'health', 'administration', ['admin.system-health'], $routeName, null, $moduleMaintenance)
                    : null,
                ! $isSales && $user->hasPermission('system.maintenance_manage')
                    ? $this->item('Maintenance', 'admin.maintenance.index', 'health', 'administration', ['admin.maintenance.*'], $routeName, null, $moduleMaintenance)
                    : null,
                ! $isSales && $user->can('viewDesignSystem')
                    ? $this->item('Design System', 'admin.design-system', 'administration', 'administration', ['admin.design-system'], $routeName, null, $moduleMaintenance)
                    : null,
                ! $isSales
                    ? $this->item('Changelog', 'changelogs.index', 'changelog', 'administration', ['changelogs.*'], $routeName, null, $moduleMaintenance)
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
            'maintenance' => collect($children)->contains('maintenance', true),
            'children' => $children,
        ];
    }

    private function item(string $label, string $route, string $icon, string $accent, array $activePatterns, ?string $currentRoute, string|array|null $moduleKey, array $moduleMaintenance): ?array
    {
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
            'module_key' => is_array($moduleKey) ? $moduleKey[0] : $moduleKey,
            'maintenance' => collect((array) $moduleKey)->contains(fn (string $key) => $moduleMaintenance[$key] ?? false),
            'active_patterns' => $activePatterns,
            'active' => $currentRoute !== null && Str::is($activePatterns, $currentRoute),
        ];
    }
}
