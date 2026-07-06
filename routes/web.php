<?php

use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Crm\AdminUserController;
use App\Http\Controllers\Crm\BranchController;
use App\Http\Controllers\Crm\BugReportController;
use App\Http\Controllers\Crm\ContentCalendarController;
use App\Http\Controllers\Crm\DanaTalanganController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\FeedbackReportController;
use App\Http\Controllers\Crm\DatabaseController;
use App\Http\Controllers\Crm\KavlingController;
use App\Http\Controllers\Crm\KonsumenProgressController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\LeadSourceController;
use App\Http\Controllers\Crm\ProjectController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/password/change', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::put('/password/change', [PasswordChangeController::class, 'update'])->name('password.change.update');
});

Route::middleware(['auth', 'verified', 'password.changed'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::bind('content_calendar', fn($value) => \App\Models\ContentItem::findOrFail($value));
    Route::get('content-calendar/export', [ContentCalendarController::class, 'export'])->name('content-calendar.export');
    Route::resource('content-calendar', ContentCalendarController::class);

    Route::get('/database', [DatabaseController::class, 'index'])->name('database.index');
    Route::get('/database/fetch', [DatabaseController::class, 'fetch'])->name('database.fetch');

    Route::get('/konsumen-progress', [KonsumenProgressController::class, 'index'])->name('konsumen-progress.index');
    Route::get('/konsumen-progress/stage', [KonsumenProgressController::class, 'stage'])->name('konsumen-progress.stage');
    Route::post('/konsumen-progress/sync', [KonsumenProgressController::class, 'sync'])->name('konsumen-progress.sync');

    Route::post('lead-sources/bulk-delete', [LeadSourceController::class, 'bulkDestroy'])->name('lead-sources.bulk-destroy');
    Route::post('lead-sources/{leadSource}/toggle-active', [LeadSourceController::class, 'toggleActive'])->name('lead-sources.toggle-active');
    Route::resource('lead-sources', LeadSourceController::class);

    Route::get('content-calendar/export-template', [ContentCalendarController::class, 'exportTemplate'])->name('content-calendar.export-template');
    Route::get('content-calendar/import', [ContentCalendarController::class, 'import'])->name('content-calendar.import');
    Route::post('content-calendar/import', [ContentCalendarController::class, 'importStore'])->name('content-calendar.import-store');

    Route::bind('lead', fn($v) => \App\Models\Lead::findOrFail($v));
    Route::get('leads/export', [LeadController::class, 'export'])->name('leads.export');
    Route::post('leads/bulk-delete', [LeadController::class, 'bulkDestroy'])->name('leads.bulk-destroy');
    Route::resource('leads', LeadController::class);

    Route::bind('dana_talangan', fn($v) => \App\Models\DanaTalangan::findOrFail($v));
    Route::get('dana-talangan/export', [DanaTalanganController::class, 'export'])->name('dana-talangan.export');
    Route::get('dana-talangan/export-template', [DanaTalanganController::class, 'exportTemplate'])->name('dana-talangan.export-template');
    Route::get('dana-talangan/import', [DanaTalanganController::class, 'import'])->name('dana-talangan.import');
    Route::post('dana-talangan/import', [DanaTalanganController::class, 'importStore'])->name('dana-talangan.import-store');
    Route::post('dana-talangan/bulk-delete', [DanaTalanganController::class, 'bulkDestroy'])->name('dana-talangan.bulk-destroy');
    Route::post('dana-talangan/bulk-update', [DanaTalanganController::class, 'bulkUpdate'])->name('dana-talangan.bulk-update');
    Route::resource('dana-talangan', DanaTalanganController::class);

    Route::post('feedback-reports', [FeedbackReportController::class, 'store'])->name('feedback-reports.store');
    Route::get('feedback-reports/fetch-recent', [FeedbackReportController::class, 'fetchRecent'])->name('feedback-reports.fetch-recent');
    Route::get('feedback-reports/fetch-history', [FeedbackReportController::class, 'fetchHistory'])->name('feedback-reports.fetch-history');
    Route::post('feedback-reports/{feedbackReport}/approve', [FeedbackReportController::class, 'approve'])->name('feedback-reports.approve');
    Route::post('feedback-reports/{feedbackReport}/reject', [FeedbackReportController::class, 'reject'])->name('feedback-reports.reject');
    Route::post('feedback-reports/{feedbackReport}/implement', [FeedbackReportController::class, 'markImplemented'])->name('feedback-reports.implement');
    Route::post('feedback-reports/{feedbackReport}/fix', [FeedbackReportController::class, 'markFixed'])->name('feedback-reports.fix');

    Route::middleware('role:superadmin')->group(function () {
        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::get('/branches/{branch}/assign', [BranchController::class, 'assignForm'])->name('branches.assign');
        Route::post('/branches/{branch}/assign', [BranchController::class, 'assignStore'])->name('branches.assign-store');
        Route::delete('/branches/{user}/remove-admin', [BranchController::class, 'removeAdmin'])->name('branches.remove-admin');

        Route::bind('admin_user', fn($value) => \App\Models\User::findOrFail($value));
        Route::resource('admin-users', AdminUserController::class)->except(['show']);

        Route::bind('project', fn($v) => \App\Models\LeadMaster::findOrFail($v));
        Route::resource('projects', ProjectController::class);
        Route::get('/projects/{project}/kavlings', [KavlingController::class, 'index'])->name('kavlings.index');
        Route::get('/projects/{project}/kavlings/bulk-import', [KavlingController::class, 'bulkImport'])->name('kavlings.bulk-import');
        Route::post('/projects/{project}/kavlings/bulk-store', [KavlingController::class, 'bulkStore'])->name('kavlings.bulk-store');
        Route::delete('/kavlings/{kavling}', [KavlingController::class, 'destroy'])->name('kavlings.destroy');
        Route::post('kavlings/bulk-delete', [KavlingController::class, 'bulkDestroy'])->name('kavlings.bulk-destroy');
    });
});

Route::middleware('auth')->group(function () {
    Route::post('/bug-report', [BugReportController::class, 'store'])->name('bug-report.store');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
