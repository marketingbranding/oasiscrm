<?php

use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Crm\AdminUserController;
use App\Http\Controllers\Crm\AdminUserImportController;
use App\Http\Controllers\Crm\AiChatController;
use App\Http\Controllers\Crm\BranchController;
use App\Http\Controllers\Crm\ChangelogController;
use App\Http\Controllers\Crm\CommentController;
use App\Http\Controllers\Crm\CommentModerationController;
use App\Http\Controllers\Crm\CommentThreadController;
use App\Http\Controllers\Crm\ContentCalendarController;
use App\Http\Controllers\Crm\CoordinatorSalesLeadWorkspaceController;
use App\Http\Controllers\Crm\DanaTalanganController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\DatabaseController;
use App\Http\Controllers\Crm\ExpenseCategoryController;
use App\Http\Controllers\Crm\ExpenseController;
use App\Http\Controllers\Crm\FeedbackReportController;
use App\Http\Controllers\Crm\ImpersonationController;
use App\Http\Controllers\Crm\KavlingController;
use App\Http\Controllers\Crm\KonsumenProgressController;
use App\Http\Controllers\Crm\LeadSourceController;
use App\Http\Controllers\Crm\NotificationController;
use App\Http\Controllers\Crm\OperationalMaintenanceController;
use App\Http\Controllers\Crm\PresenceController;
use App\Http\Controllers\Crm\ProjectController;
use App\Http\Controllers\Crm\SalesAgendaController;
use App\Http\Controllers\Crm\SalesAgendaExportController;
use App\Http\Controllers\Crm\SalesDailyReminderController;
use App\Http\Controllers\Crm\SalesFeeReportController;
use App\Http\Controllers\Crm\SalesLeadController;
use App\Http\Controllers\Crm\SalesLeadLifecycleController;
use App\Http\Controllers\Crm\SalesLeadLifecycleSyncController;
use App\Http\Controllers\Crm\SalesLeadOptionController;
use App\Http\Controllers\Crm\SalesLeadStageController;
use App\Http\Controllers\Crm\SalesPocketbookController;
use App\Http\Controllers\Crm\SalesSheetIdentityController;
use App\Http\Controllers\Crm\SupervisorSalesPocketbookController;
use App\Http\Controllers\Crm\SystemHealthController;
use App\Http\Controllers\ProfileController;
use App\Models\Comment;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\LeadMaster;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route(auth()->user()->landingRouteName())
        : redirect()->route('login');
});

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::get('/password/change', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::put('/password/change', [PasswordChangeController::class, 'update'])->name('password.change.update');
    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');
});

Route::middleware(['auth', 'active', 'verified', 'password.changed', 'operational.maintenance', 'sales.access'])->group(function () {
    Route::bind('comment', fn ($value) => Comment::withTrashed()->findOrFail($value));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/comments', [CommentController::class, 'index'])->middleware(['permission:comments.view', 'throttle:120,1'])->name('comments.index');
    Route::post('/comments', [CommentController::class, 'store'])->middleware(['permission:comments.view', 'throttle:30,1'])->name('comments.store');
    Route::get('/comments/mentionable-users', [CommentController::class, 'mentionableUsers'])->middleware(['permission:comments.mention', 'throttle:120,1'])->name('comments.mentionable-users');
    Route::get('/comments/thread/{alias}/{id}', [CommentThreadController::class, 'show'])->middleware('permission:comments.view')->name('comments.thread');
    Route::patch('/comments/{comment}', [CommentController::class, 'update'])->middleware(['permission:comments.view', 'throttle:60,1'])->name('comments.update');
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->middleware(['permission:comments.view', 'throttle:60,1'])->name('comments.destroy');
    Route::post('/comments/{comment}/restore', [CommentController::class, 'restore'])->middleware(['permission:comments.moderate', 'throttle:60,1'])->name('comments.restore');
    Route::get('/comments/{comment}/history', [CommentController::class, 'history'])->middleware(['permission:comments.view', 'throttle:120,1'])->name('comments.history');
    Route::post('/comments/{comment}/moderate', [CommentModerationController::class, 'store'])->middleware(['permission:comments.moderate', 'throttle:60,1'])->name('comments.moderate');

    Route::get('/buku-saku-sales', [SalesPocketbookController::class, 'index'])->name('sales-pocketbook.index');
    Route::get('/sales-fee-reports', [SalesFeeReportController::class, 'index'])->name('sales-fee-reports.index');
    Route::get('/sales-fee-reports/{salesUser}/{project}', [SalesFeeReportController::class, 'show'])->name('sales-fee-reports.show');
    Route::get('/sales-fee-reports/{salesUser}/{project}/print', [SalesFeeReportController::class, 'print'])->name('sales-fee-reports.print');
    Route::get('/buku-saku-sales/supervisor/agenda-export', [SupervisorSalesPocketbookController::class, 'agendaExport'])->middleware('permission:sales_pocketbook.export')->name('sales-pocketbook.supervisor-monitoring.agenda-export');
    Route::get('/buku-saku-sales/supervisor/lead-export', [SupervisorSalesPocketbookController::class, 'leadExport'])->middleware('permission:sales_pocketbook.export')->name('sales-pocketbook.supervisor-monitoring.lead-export');
    Route::get('/buku-saku-sales/export', [SalesPocketbookController::class, 'export'])->middleware('permission:sales_pocketbook.export')->name('sales-pocketbook.export');
    Route::post('/buku-saku-sales/lifecycle-sync', [SalesLeadLifecycleSyncController::class, 'sync'])->middleware('permission:sales_pocketbook.sync')->name('sales-pocketbook.lifecycle-sync');
    Route::get('/buku-saku-sales/lifecycle-sync/status', [SalesLeadLifecycleSyncController::class, 'status'])->name('sales-pocketbook.lifecycle-sync.status');
    Route::get('/buku-saku-sales/lifecycle-reconciliations', [SalesLeadLifecycleSyncController::class, 'reconciliations'])->middleware('permission:sales_pocketbook.reconcile')->name('sales-pocketbook.lifecycle-reconciliations.index');
    Route::get('/buku-saku-sales/agendas/workspace', [SalesAgendaController::class, 'index'])->middleware('permission:sales_pocketbook.view_own')->name('sales-agendas.index');
    Route::get('/buku-saku-sales/agendas/export', SalesAgendaExportController::class)->middleware('permission:sales_pocketbook.export_own')->name('sales-agendas.export');
    Route::post('/buku-saku-sales/agendas', [SalesAgendaController::class, 'store'])->name('sales-agendas.store');
    Route::patch('/buku-saku-sales/agendas/{agenda}', [SalesAgendaController::class, 'update'])->name('sales-agendas.update');
    Route::post('/buku-saku-sales/agendas/{agenda}/reschedule', [SalesAgendaController::class, 'reschedule'])->name('sales-agendas.reschedule');
    Route::get('/buku-saku-sales/input', [SalesLeadController::class, 'create'])->name('sales-leads.create');
    Route::get('/buku-saku-sales/duplicate-phone', [SalesLeadController::class, 'duplicatePhone'])->name('sales-leads.duplicate-phone');
    Route::get('/buku-saku-sales/branches/{branch}/lead-options', SalesLeadOptionController::class)->name('sales-leads.options');
    Route::get('/buku-saku-sales/leads/export', [CoordinatorSalesLeadWorkspaceController::class, 'export'])->middleware('permission:sales_pocketbook.export_team')->name('coordinator-leads.export');
    Route::post('/buku-saku-sales/leads/sync', [CoordinatorSalesLeadWorkspaceController::class, 'push'])->middleware('permission:sales_pocketbook.sync')->name('coordinator-leads.sync');
    Route::post('/buku-saku-sales/leads', [SalesLeadController::class, 'store'])->name('sales-leads.store');
    Route::get('/buku-saku-sales/leads/{sales_lead}', [SalesLeadController::class, 'show'])->name('sales-leads.show');
    Route::get('/buku-saku-sales/leads/{sales_lead}/edit', [SalesLeadController::class, 'edit'])->name('sales-leads.edit');
    Route::put('/buku-saku-sales/leads/{sales_lead}', [SalesLeadController::class, 'update'])->name('sales-leads.update');
    Route::patch('/buku-saku-sales/leads/{sales_lead}/stage', [SalesLeadStageController::class, 'update'])->name('sales-leads.stage.update');
    Route::patch('/buku-saku-sales/leads/{sales_lead}/lifecycle-status', [SalesLeadLifecycleController::class, 'updateStatus'])->name('sales-leads.lifecycle-status.update');
    Route::post('/buku-saku-sales/leads/{sales_lead}/site-visits', [SalesLeadLifecycleController::class, 'siteVisit'])->name('sales-leads.site-visits.store');
    Route::patch('/buku-saku-sales/leads/{sales_lead}/site-visits/{site_visit}', [SalesLeadLifecycleController::class, 'updateSiteVisit'])->name('sales-leads.site-visits.update');
    Route::post('/buku-saku-sales/leads/{sales_lead}/consumer', [SalesLeadLifecycleController::class, 'consumer'])->name('sales-leads.consumer.store');
    Route::post('/buku-saku-sales/leads/{sales_lead}/slik', [SalesLeadLifecycleController::class, 'slik'])->name('sales-leads.slik.store');
    Route::patch('/buku-saku-sales/leads/{sales_lead}/slik/{slik_attempt}/reject', [SalesLeadLifecycleController::class, 'rejectSlik'])->name('sales-leads.slik.reject');
    Route::post('/buku-saku-sales/leads/{sales_lead}/freelance', [SalesLeadLifecycleController::class, 'freelance'])->name('sales-leads.freelance.store');
    Route::post('/sales-reminders/dismiss', [SalesDailyReminderController::class, 'dismiss'])->name('sales-reminders.dismiss');

    Route::post('/presence/heartbeat', [PresenceController::class, 'heartbeat'])->middleware('throttle:180,1')->name('presence.heartbeat');
    Route::get('/presence', [PresenceController::class, 'index'])->middleware('throttle:240,1')->name('presence.index');
    Route::delete('/presence', [PresenceController::class, 'destroy'])->middleware('throttle:180,1')->name('presence.destroy');

    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('throttle:120,1')->name('notifications.index');
    Route::get('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::get('/ai-chat', [AiChatController::class, 'index'])->name('ai-chat.index');
    Route::get('/ai-chat/{conversation}', [AiChatController::class, 'show'])->name('ai-chat.show');
    Route::post('/ai-chat', [AiChatController::class, 'chat'])->middleware('throttle:30,60')->name('ai-chat.chat');
    Route::delete('/ai-chat/{conversation}', [AiChatController::class, 'destroy'])->name('ai-chat.destroy');

    Route::bind('content_calendar', fn ($value) => ContentItem::findOrFail($value));
    Route::get('content-calendar/export', [ContentCalendarController::class, 'export'])->middleware('permission:work_planner.export')->name('content-calendar.export');
    Route::get('content-calendar/export-template', [ContentCalendarController::class, 'exportTemplate'])->middleware('permission:work_planner.export')->name('content-calendar.export-template');
    Route::get('content-calendar/import', [ContentCalendarController::class, 'import'])->middleware('permission:work_planner.create')->name('content-calendar.import');
    Route::post('content-calendar/import', [ContentCalendarController::class, 'importStore'])->middleware('permission:work_planner.create')->name('content-calendar.import-store');
    Route::get('content-calendar/{content_calendar}/detail', [ContentCalendarController::class, 'detail'])->name('content-calendar.detail');
    Route::patch('content-calendar/{content_calendar}/status', [ContentCalendarController::class, 'updateStatus'])->middleware('permission:work_planner.update')->name('content-calendar.update-status');
    Route::post('content-calendar/bulk-update', [ContentCalendarController::class, 'bulkUpdate'])->middleware('permission:work_planner.update')->name('content-calendar.bulk-update');
    Route::post('content-calendar/bulk-delete', [ContentCalendarController::class, 'bulkDelete'])->middleware('permission:work_planner.update')->name('content-calendar.bulk-delete');
    Route::get('content-calendar', [ContentCalendarController::class, 'index'])->name('content-calendar.index');
    Route::get('content-calendar/create', [ContentCalendarController::class, 'create'])->middleware('permission:work_planner.create')->name('content-calendar.create');
    Route::post('content-calendar', [ContentCalendarController::class, 'store'])->middleware('permission:work_planner.create')->name('content-calendar.store');
    Route::get('content-calendar/{content_calendar}', [ContentCalendarController::class, 'show'])->name('content-calendar.show');
    Route::get('content-calendar/{content_calendar}/edit', [ContentCalendarController::class, 'edit'])->middleware('permission:work_planner.update')->name('content-calendar.edit');
    Route::match(['put', 'patch'], 'content-calendar/{content_calendar}', [ContentCalendarController::class, 'update'])->middleware('permission:work_planner.update')->name('content-calendar.update');
    Route::delete('content-calendar/{content_calendar}', [ContentCalendarController::class, 'destroy'])->middleware('permission:work_planner.update')->name('content-calendar.destroy');

    Route::get('/database', [DatabaseController::class, 'index'])->middleware('permission:database.view')->name('database.index');
    Route::get('/database/sheet/{branchId}/{sheetName}', [DatabaseController::class, 'sheetData'])->middleware('permission:database.view')->name('database.sheet');
    Route::post('/database/sync', [DatabaseController::class, 'sync'])->middleware('permission:database.sync')->name('database.sync');
    Route::get('/database/sync/status', [DatabaseController::class, 'syncStatus'])->middleware('permission:database.sync')->name('database.sync-status');
    Route::post('/database/records', [DatabaseController::class, 'store'])->middleware('permission:database.edit')->name('database.records.store');
    Route::put('/database/records/{record}', [DatabaseController::class, 'update'])->middleware('permission:database.edit')->name('database.records.update');
    Route::delete('/database/records/{record}', [DatabaseController::class, 'destroy'])->middleware('permission:database.edit')->name('database.records.destroy');

    Route::get('/konsumen-progress', [KonsumenProgressController::class, 'index'])->middleware('permission:consumer_progress.view')->name('konsumen-progress.index');
    Route::get('/konsumen-progress/stage', [KonsumenProgressController::class, 'stage'])->middleware('permission:consumer_progress.view')->name('konsumen-progress.stage');
    Route::post('/konsumen-progress/sync', [KonsumenProgressController::class, 'sync'])->middleware('permission:consumer_progress.sync')->name('konsumen-progress.sync');
    Route::get('/konsumen-progress/sync/status', [KonsumenProgressController::class, 'syncStatus'])->middleware('permission:consumer_progress.sync')->name('konsumen-progress.sync-status');

    Route::get('changelogs', [ChangelogController::class, 'index'])->name('changelogs.index');

    Route::post('lead-sources/bulk-delete', [LeadSourceController::class, 'bulkDestroy'])->name('lead-sources.bulk-destroy');
    Route::post('lead-sources/{leadSource}/toggle-active', [LeadSourceController::class, 'toggleActive'])->name('lead-sources.toggle-active');
    Route::resource('lead-sources', LeadSourceController::class);

    Route::bind('dana_talangan', fn ($v) => DanaTalangan::findOrFail($v));
    Route::get('dana-talangan/kavling-options', [DanaTalanganController::class, 'kavlingOptions'])->middleware('permission:bridge_fund.view')->name('dana-talangan.kavling-options');
    Route::post('dana-talangan/sync', [DanaTalanganController::class, 'sync'])->middleware('permission:bridge_fund.manage')->name('dana-talangan.sync');
    Route::get('dana-talangan/sync/status', [DanaTalanganController::class, 'syncStatus'])->middleware('permission:bridge_fund.manage')->name('dana-talangan.sync-status');
    Route::get('dana-talangan/export', [DanaTalanganController::class, 'export'])->middleware('permission:bridge_fund.export')->name('dana-talangan.export');
    Route::get('dana-talangan/export-template', [DanaTalanganController::class, 'exportTemplate'])->middleware('permission:bridge_fund.export')->name('dana-talangan.export-template');
    Route::get('dana-talangan/import', [DanaTalanganController::class, 'import'])->middleware('permission:bridge_fund.manage')->name('dana-talangan.import');
    Route::post('dana-talangan/import', [DanaTalanganController::class, 'importStore'])->middleware('permission:bridge_fund.manage')->name('dana-talangan.import-store');
    Route::post('dana-talangan/bulk-delete', [DanaTalanganController::class, 'bulkDestroy'])->middleware('permission:bridge_fund.manage')->name('dana-talangan.bulk-destroy');
    Route::post('dana-talangan/bulk-update', [DanaTalanganController::class, 'bulkUpdate'])->middleware('permission:bridge_fund.manage')->name('dana-talangan.bulk-update');
    Route::get('dana-talangan/{dana_talangan}/detail', [DanaTalanganController::class, 'detail'])->middleware('permission:bridge_fund.view')->name('dana-talangan.detail');
    Route::resource('dana-talangan', DanaTalanganController::class)->middlewareFor(['index', 'show'], 'permission:bridge_fund.view')->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:bridge_fund.manage');

    Route::post('feedback-reports', [FeedbackReportController::class, 'store'])->middleware('throttle:10,1')->name('feedback-reports.store');
    Route::get('feedback-reports/history', [FeedbackReportController::class, 'history'])->name('feedback-reports.history');
    Route::get('feedback-reports/{feedbackReport}/screenshot', [FeedbackReportController::class, 'screenshot'])->name('feedback-reports.screenshot');
    Route::get('feedback-reports', [FeedbackReportController::class, 'index'])->name('feedback-reports.index');
    Route::get('feedback-reports/{feedbackReport}', [FeedbackReportController::class, 'show'])->name('feedback-reports.show');
    Route::patch('feedback-reports/{feedbackReport}', [FeedbackReportController::class, 'review'])->name('feedback-reports.review');

    Route::middleware('permission:expenses.manage_categories')->group(function () {
        Route::get('pengeluaran/kategori', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
        Route::post('pengeluaran/kategori', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        Route::put('pengeluaran/kategori/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
        Route::patch('pengeluaran/kategori/{expenseCategory}/toggle', [ExpenseCategoryController::class, 'toggle'])->name('expense-categories.toggle');
    });

    Route::get('pengeluaran', [ExpenseController::class, 'index'])->middleware('permission:expenses.view')->name('expenses.index');
    Route::get('pengeluaran/create', [ExpenseController::class, 'create'])->middleware('permission:expenses.create')->name('expenses.create');
    Route::post('pengeluaran', [ExpenseController::class, 'store'])->middleware('permission:expenses.create')->name('expenses.store');
    Route::get('pengeluaran/projects', [ExpenseController::class, 'projects'])->middleware('permission:expenses.view')->name('expenses.projects');
    Route::get('pengeluaran/export', [ExpenseController::class, 'export'])->middleware('permission:expenses.export')->name('expenses.export');
    Route::get('pengeluaran/{expense}', [ExpenseController::class, 'show'])->middleware('permission:expenses.view')->name('expenses.show');
    Route::get('pengeluaran/{expense}/edit', [ExpenseController::class, 'edit'])->middleware('permission:expenses.update')->name('expenses.edit');
    Route::put('pengeluaran/{expense}', [ExpenseController::class, 'update'])->middleware('permission:expenses.update')->name('expenses.update');
    Route::patch('pengeluaran/{expense}/cancel', [ExpenseController::class, 'cancel'])->middleware('permission:expenses.cancel')->name('expenses.cancel');

    Route::middleware('role:superadmin')->group(function () {
        Route::resource('changelogs', ChangelogController::class)->except('index');
    });

    Route::get('/admin/system-health', SystemHealthController::class)->middleware('permission:system_health.view')->name('admin.system-health');
    Route::middleware('permission:system.maintenance_manage')->prefix('/admin/maintenance')->name('admin.maintenance.')->group(function () {
        Route::get('/', [OperationalMaintenanceController::class, 'index'])->name('index');
        Route::put('/enable', [OperationalMaintenanceController::class, 'enable'])->name('enable');
        Route::put('/disable', [OperationalMaintenanceController::class, 'disable'])->name('disable');
    });
    Route::view('/admin/design-system', 'crm.design-system.index')->middleware('can:viewDesignSystem')->name('admin.design-system');
    Route::middleware('permission:branches.manage')->group(function () {
        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->middleware('not.impersonating')->name('branches.update');
        Route::get('/branches/{branch}/assign', [BranchController::class, 'assignForm'])->name('branches.assign');
        Route::post('/branches/{branch}/assign', [BranchController::class, 'assignStore'])->middleware('not.impersonating')->name('branches.assign-store');
        Route::delete('/branches/{user}/remove-admin', [BranchController::class, 'removeAdmin'])->middleware('not.impersonating')->name('branches.remove-admin');

    });
    Route::middleware('permission:projects.manage')->group(function () {
        Route::bind('project', fn ($v) => LeadMaster::findOrFail($v));
        Route::resource('projects', ProjectController::class)
            ->middlewareFor(['store', 'update', 'destroy'], 'not.impersonating');
        Route::get('/projects/{project}/kavlings', [KavlingController::class, 'index'])->name('kavlings.index');
        Route::get('/projects/{project}/kavlings/bulk-import', [KavlingController::class, 'bulkImport'])->name('kavlings.bulk-import');
        Route::post('/projects/{project}/kavlings/bulk-store', [KavlingController::class, 'bulkStore'])->middleware('not.impersonating')->name('kavlings.bulk-store');
        Route::delete('/kavlings/{kavling}', [KavlingController::class, 'destroy'])->middleware('not.impersonating')->name('kavlings.destroy');
        Route::post('kavlings/bulk-delete', [KavlingController::class, 'bulkDestroy'])->middleware('not.impersonating')->name('kavlings.bulk-destroy');
    });

    Route::prefix('admin-users')->name('admin-users.')->group(function () {
        Route::post('/{target}/impersonate', [ImpersonationController::class, 'start'])->middleware('not.impersonating')->name('impersonate');
        Route::middleware('permissions.all:users.create,users.invite,users.assign_roles,users.assign_branches,users.assign_projects,users.assign_supervisor')->group(function () {
            Route::get('/import', [AdminUserImportController::class, 'create'])->name('import');
            Route::post('/import/preview', [AdminUserImportController::class, 'preview'])->name('import-preview');
            Route::post('/import/confirm', [AdminUserImportController::class, 'confirm'])->middleware('not.impersonating')->name('import-confirm');
            Route::get('/import/template', [AdminUserImportController::class, 'template'])->name('import-template');
            Route::get('/import/history', [AdminUserImportController::class, 'history'])->name('import-history');
            Route::get('/import/batches/{user_import_batch}/result', [AdminUserImportController::class, 'result'])->name('import-result');
            Route::get('/import/batches/{user_import_batch}/credentials', [AdminUserImportController::class, 'credentials'])
                ->middleware(['signed', 'throttle:3,1', 'not.impersonating'])->name('import-credentials');
            Route::get('/import/batches/{user_import_batch}', [AdminUserImportController::class, 'show'])->name('import-batches.show');
        });
        Route::get('/', [AdminUserController::class, 'index'])->middleware('permission:users.view')->name('index');
        Route::get('/create', [AdminUserController::class, 'create'])->middleware('permission:users.create')->name('create');
        Route::post('/', [AdminUserController::class, 'store'])->middleware(['permission:users.create', 'not.impersonating'])->name('store');
        Route::post('/bulk-reset-access', [AdminUserController::class, 'bulkResetAccess'])->middleware(['permission:users.reset_password', 'not.impersonating'])->name('bulk-reset-access');
        Route::get('/{admin_user}', [AdminUserController::class, 'show'])->middleware('permission:users.view')->name('show');
        Route::get('/{admin_user}/edit', [AdminUserController::class, 'edit'])->middleware('permission:users.update')->name('edit');
        Route::put('/{admin_user}', [AdminUserController::class, 'update'])->middleware(['permission:users.update', 'not.impersonating'])->name('update');
        Route::get('/{admin_user}/sales-sheet-identities/{branch}', [SalesSheetIdentityController::class, 'edit'])->middleware('permission:users.update')->name('sales-sheet-identity.edit');
        Route::put('/{admin_user}/sales-sheet-identities/{branch}', [SalesSheetIdentityController::class, 'update'])->middleware(['permission:users.update', 'not.impersonating'])->name('sales-sheet-identity.update');
        Route::post('/{admin_user}/invitation', [AdminUserController::class, 'sendInvitation'])->middleware(['permission:users.invite', 'not.impersonating'])->name('invitation.send');
        Route::post('/{admin_user}/invitation/resend', [AdminUserController::class, 'resendInvitation'])->middleware(['permission:users.invite', 'not.impersonating'])->name('invitation.resend');
        Route::patch('/{admin_user}/invitation/revoke', [AdminUserController::class, 'revokeInvitation'])->middleware(['permission:users.invite', 'not.impersonating'])->name('invitation.revoke');
        Route::patch('/{admin_user}/suspend', [AdminUserController::class, 'suspend'])->middleware(['permission:users.suspend', 'not.impersonating'])->name('suspend');
        Route::patch('/{admin_user}/reactivate', [AdminUserController::class, 'reactivate'])->middleware(['permission:users.reactivate', 'not.impersonating'])->name('reactivate');
        Route::patch('/{admin_user}/deactivate', [AdminUserController::class, 'deactivate'])->middleware(['permission:users.deactivate', 'not.impersonating'])->name('deactivate');
        Route::patch('/{admin_user}/anonymize', [AdminUserController::class, 'anonymize'])->middleware(['permission:users.anonymize', 'not.impersonating'])->name('anonymize');
        Route::patch('/{admin_user}/release-email', [AdminUserController::class, 'releaseEmail'])->middleware(['permission:users.release_email', 'not.impersonating'])->name('release-email');
        Route::delete('/{admin_user}', [AdminUserController::class, 'destroy'])->middleware(['permission:users.delete_permanently', 'not.impersonating'])->name('destroy');
        Route::post('/{admin_user}/reset-access', [AdminUserController::class, 'resetAccess'])->middleware(['permission:users.reset_password', 'not.impersonating'])->name('reset-access');
    });
});

Route::middleware(['auth', 'active', 'verified', 'password.changed', 'operational.maintenance'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
