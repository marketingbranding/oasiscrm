<?php

use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Crm\AdminUserController;
use App\Http\Controllers\Crm\AiChatController;
use App\Http\Controllers\Crm\BranchController;
use App\Http\Controllers\Crm\ChangelogController;
use App\Http\Controllers\Crm\ContentCalendarController;
use App\Http\Controllers\Crm\DanaTalanganController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\DatabaseController;
use App\Http\Controllers\Crm\FeedbackReportController;
use App\Http\Controllers\Crm\KavlingController;
use App\Http\Controllers\Crm\KonsumenProgressController;
use App\Http\Controllers\Crm\LeadSourceController;
use App\Http\Controllers\Crm\NotificationController;
use App\Http\Controllers\Crm\PresenceController;
use App\Http\Controllers\Crm\ProjectController;
use App\Http\Controllers\Crm\SalesAgendaController;
use App\Http\Controllers\Crm\SalesLeadController;
use App\Http\Controllers\Crm\SalesLeadStageController;
use App\Http\Controllers\Crm\SalesPocketbookController;
use App\Http\Controllers\Crm\SystemHealthController;
use App\Http\Controllers\ProfileController;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\LeadMaster;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route(auth()->user()->landingRouteName())
        : redirect()->route('login');
});

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::get('/password/change', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::put('/password/change', [PasswordChangeController::class, 'update'])->name('password.change.update');
});

Route::middleware(['auth', 'active', 'verified', 'password.changed', 'sales.access'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/buku-saku-sales', [SalesPocketbookController::class, 'index'])->name('sales-pocketbook.index');
    Route::get('/buku-saku-sales/export', [SalesPocketbookController::class, 'export'])->name('sales-pocketbook.export');
    Route::post('/buku-saku-sales/agendas', [SalesAgendaController::class, 'store'])->name('sales-agendas.store');
    Route::patch('/buku-saku-sales/agendas/{agenda}', [SalesAgendaController::class, 'update'])->name('sales-agendas.update');
    Route::post('/buku-saku-sales/agendas/{agenda}/reschedule', [SalesAgendaController::class, 'reschedule'])->name('sales-agendas.reschedule');
    Route::get('/buku-saku-sales/input', [SalesLeadController::class, 'create'])->name('sales-leads.create');
    Route::get('/buku-saku-sales/duplicate-phone', [SalesLeadController::class, 'duplicatePhone'])->name('sales-leads.duplicate-phone');
    Route::post('/buku-saku-sales/leads', [SalesLeadController::class, 'store'])->name('sales-leads.store');
    Route::get('/buku-saku-sales/leads/{sales_lead}/edit', [SalesLeadController::class, 'edit'])->name('sales-leads.edit');
    Route::put('/buku-saku-sales/leads/{sales_lead}', [SalesLeadController::class, 'update'])->name('sales-leads.update');
    Route::patch('/buku-saku-sales/leads/{sales_lead}/stage', [SalesLeadStageController::class, 'update'])->name('sales-leads.stage.update');

    Route::post('/presence/heartbeat', [PresenceController::class, 'heartbeat'])->middleware('throttle:180,1')->name('presence.heartbeat');
    Route::get('/presence', [PresenceController::class, 'index'])->middleware('throttle:240,1')->name('presence.index');
    Route::delete('/presence', [PresenceController::class, 'destroy'])->middleware('throttle:180,1')->name('presence.destroy');

    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('throttle:120,1')->name('notifications.index');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::get('/ai-chat', [AiChatController::class, 'index'])->name('ai-chat.index');
    Route::get('/ai-chat/{conversation}', [AiChatController::class, 'show'])->name('ai-chat.show');
    Route::post('/ai-chat', [AiChatController::class, 'chat'])->middleware('throttle:30,60')->name('ai-chat.chat');
    Route::delete('/ai-chat/{conversation}', [AiChatController::class, 'destroy'])->name('ai-chat.destroy');

    Route::bind('content_calendar', fn ($value) => ContentItem::findOrFail($value));
    Route::get('content-calendar/export', [ContentCalendarController::class, 'export'])->name('content-calendar.export');
    Route::get('content-calendar/export-template', [ContentCalendarController::class, 'exportTemplate'])->name('content-calendar.export-template');
    Route::get('content-calendar/import', [ContentCalendarController::class, 'import'])->name('content-calendar.import');
    Route::post('content-calendar/import', [ContentCalendarController::class, 'importStore'])->name('content-calendar.import-store');
    Route::get('content-calendar/{content_calendar}/detail', [ContentCalendarController::class, 'detail'])->name('content-calendar.detail');
    Route::patch('content-calendar/{content_calendar}/status', [ContentCalendarController::class, 'updateStatus'])->name('content-calendar.update-status');
    Route::post('content-calendar/bulk-update', [ContentCalendarController::class, 'bulkUpdate'])->name('content-calendar.bulk-update');
    Route::post('content-calendar/bulk-delete', [ContentCalendarController::class, 'bulkDelete'])->name('content-calendar.bulk-delete');
    Route::resource('content-calendar', ContentCalendarController::class);

    Route::get('/database', [DatabaseController::class, 'index'])->name('database.index');
    Route::get('/database/sheet/{branchId}/{sheetName}', [DatabaseController::class, 'sheetData'])->name('database.sheet');
    Route::post('/database/sync', [DatabaseController::class, 'sync'])->name('database.sync');
    Route::get('/database/sync/status', [DatabaseController::class, 'syncStatus'])->name('database.sync-status');
    Route::post('/database/records', [DatabaseController::class, 'store'])->name('database.records.store');
    Route::put('/database/records/{record}', [DatabaseController::class, 'update'])->name('database.records.update');
    Route::delete('/database/records/{record}', [DatabaseController::class, 'destroy'])->name('database.records.destroy');

    Route::get('/konsumen-progress', [KonsumenProgressController::class, 'index'])->name('konsumen-progress.index');
    Route::get('/konsumen-progress/stage', [KonsumenProgressController::class, 'stage'])->name('konsumen-progress.stage');
    Route::post('/konsumen-progress/sync', [KonsumenProgressController::class, 'sync'])->name('konsumen-progress.sync');
    Route::get('/konsumen-progress/sync/status', [KonsumenProgressController::class, 'syncStatus'])->name('konsumen-progress.sync-status');

    Route::get('changelogs', [ChangelogController::class, 'index'])->name('changelogs.index');

    Route::post('lead-sources/bulk-delete', [LeadSourceController::class, 'bulkDestroy'])->name('lead-sources.bulk-destroy');
    Route::post('lead-sources/{leadSource}/toggle-active', [LeadSourceController::class, 'toggleActive'])->name('lead-sources.toggle-active');
    Route::resource('lead-sources', LeadSourceController::class);

    Route::bind('dana_talangan', fn ($v) => DanaTalangan::findOrFail($v));
    Route::get('dana-talangan/kavling-options', [DanaTalanganController::class, 'kavlingOptions'])->name('dana-talangan.kavling-options');
    Route::post('dana-talangan/sync', [DanaTalanganController::class, 'sync'])->name('dana-talangan.sync');
    Route::get('dana-talangan/sync/status', [DanaTalanganController::class, 'syncStatus'])->name('dana-talangan.sync-status');
    Route::get('dana-talangan/export', [DanaTalanganController::class, 'export'])->name('dana-talangan.export');
    Route::get('dana-talangan/export-template', [DanaTalanganController::class, 'exportTemplate'])->name('dana-talangan.export-template');
    Route::get('dana-talangan/import', [DanaTalanganController::class, 'import'])->name('dana-talangan.import');
    Route::post('dana-talangan/import', [DanaTalanganController::class, 'importStore'])->name('dana-talangan.import-store');
    Route::post('dana-talangan/bulk-delete', [DanaTalanganController::class, 'bulkDestroy'])->name('dana-talangan.bulk-destroy');
    Route::post('dana-talangan/bulk-update', [DanaTalanganController::class, 'bulkUpdate'])->name('dana-talangan.bulk-update');
    Route::get('dana-talangan/{dana_talangan}/detail', [DanaTalanganController::class, 'detail'])->name('dana-talangan.detail');
    Route::resource('dana-talangan', DanaTalanganController::class);

    Route::post('feedback-reports', [FeedbackReportController::class, 'store'])->middleware('throttle:10,1')->name('feedback-reports.store');
    Route::get('feedback-reports/history', [FeedbackReportController::class, 'history'])->name('feedback-reports.history');
    Route::get('feedback-reports/{feedbackReport}/screenshot', [FeedbackReportController::class, 'screenshot'])->name('feedback-reports.screenshot');
    Route::get('feedback-reports', [FeedbackReportController::class, 'index'])->name('feedback-reports.index');
    Route::get('feedback-reports/{feedbackReport}', [FeedbackReportController::class, 'show'])->name('feedback-reports.show');
    Route::patch('feedback-reports/{feedbackReport}', [FeedbackReportController::class, 'review'])->name('feedback-reports.review');

    Route::middleware('role:superadmin')->group(function () {
        Route::get('/admin/system-health', SystemHealthController::class)->name('admin.system-health');
        Route::resource('changelogs', ChangelogController::class)->except('index');

        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::get('/branches/{branch}/assign', [BranchController::class, 'assignForm'])->name('branches.assign');
        Route::post('/branches/{branch}/assign', [BranchController::class, 'assignStore'])->name('branches.assign-store');
        Route::delete('/branches/{user}/remove-admin', [BranchController::class, 'removeAdmin'])->name('branches.remove-admin');

        Route::bind('admin_user', fn ($value) => User::findOrFail($value));
        Route::resource('admin-users', AdminUserController::class)->except(['show']);

        Route::bind('project', fn ($v) => LeadMaster::findOrFail($v));
        Route::resource('projects', ProjectController::class);
        Route::get('/projects/{project}/kavlings', [KavlingController::class, 'index'])->name('kavlings.index');
        Route::get('/projects/{project}/kavlings/bulk-import', [KavlingController::class, 'bulkImport'])->name('kavlings.bulk-import');
        Route::post('/projects/{project}/kavlings/bulk-store', [KavlingController::class, 'bulkStore'])->name('kavlings.bulk-store');
        Route::delete('/kavlings/{kavling}', [KavlingController::class, 'destroy'])->name('kavlings.destroy');
        Route::post('kavlings/bulk-delete', [KavlingController::class, 'bulkDestroy'])->name('kavlings.bulk-destroy');
    });
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
