<?php

use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Crm\AdminUserController;
use App\Http\Controllers\Crm\BranchController;
use App\Http\Controllers\Crm\BugReportController;
use App\Http\Controllers\Crm\ContentCalendarController;
use App\Http\Controllers\Crm\DanaTalanganController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\DatabaseController;
use App\Http\Controllers\Crm\KavlingController;
use App\Http\Controllers\Crm\LeadDailyController;
use App\Http\Controllers\Crm\LeadEventController;
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
    Route::resource('content-calendar', ContentCalendarController::class);

    Route::get('/database', [DatabaseController::class, 'index'])->name('database.index');
    Route::get('/database/fetch', [DatabaseController::class, 'fetch'])->name('database.fetch');

    Route::bind('lead_event', fn($v) => \App\Models\LeadEvent::findOrFail($v));
    Route::resource('lead-events', LeadEventController::class);

    Route::bind('lead_daily', fn($v) => \App\Models\LeadDaily::findOrFail($v));
    Route::resource('lead-daily', LeadDailyController::class);

    Route::bind('dana_talangan', fn($v) => \App\Models\DanaTalangan::findOrFail($v));
    Route::resource('dana-talangan', DanaTalanganController::class);

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
    });
});

Route::middleware('auth')->group(function () {
    Route::post('/bug-report', [BugReportController::class, 'store'])->name('bug-report.store');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
