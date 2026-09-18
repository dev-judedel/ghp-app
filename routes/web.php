<?php

use App\Http\Controllers\Admin\MemberBulkActionController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AmountAdjustmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BenefitPeriodController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataQualityController;
use App\Http\Controllers\DependentController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReimbursementController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.update-password');

    Route::get('/members', [MemberController::class, 'index'])->name('members.index');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/annual-ghp', [ReportController::class, 'annualGhp'])->name('reports.annual-ghp');
    Route::get('/reports/annual-ghp/csv', [ReportController::class, 'annualGhpCsv'])->name('reports.annual-ghp.csv');
    Route::get('/reports/reimbursements', [ReportController::class, 'reimbursements'])->name('reports.reimbursements');
    Route::get('/reports/reimbursements/csv', [ReportController::class, 'reimbursementsCsv'])->name('reports.reimbursements.csv');
    Route::get('/members/{member}/mdr', [ReportController::class, 'memberDataRecord'])->name('members.mdr');

    Route::middleware('admin')->group(function () {
        Route::post('/members/bulk-action', [MemberBulkActionController::class, 'store'])->name('members.bulk-action');
        Route::post('/members', [MemberController::class, 'store'])->name('members.store');
        Route::get('/members/import/template', [MemberImportController::class, 'template'])->name('members.import.template');
        Route::post('/members/import', [MemberImportController::class, 'import'])->name('members.import');
        Route::put('/members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::post('/members/{member}/generate-benefit-period', [MemberController::class, 'generateBenefitPeriod'])->name('members.generate-benefit-period');
        Route::patch('/members/{member}/status', [MemberController::class, 'updateStatus'])->name('members.update-status');
        Route::post('/members/{member}/reimbursements', [ReimbursementController::class, 'store'])->name('members.reimbursements.store');
        Route::put('/members/{member}/reimbursements/{reimbursement}', [ReimbursementController::class, 'update'])->name('members.reimbursements.update');
        Route::post('/members/{member}/reimbursements/{reimbursement}/void', [ReimbursementController::class, 'void'])->name('members.reimbursements.void');
        Route::post('/members/{member}/reimbursements/{reimbursement}/unvoid', [ReimbursementController::class, 'unvoid'])->name('members.reimbursements.unvoid');

        Route::post('/members/{member}/dependents', [DependentController::class, 'store'])->name('members.dependents.store');
        Route::put('/members/{member}/dependents/{dependent}', [DependentController::class, 'update'])->name('members.dependents.update');
        Route::delete('/members/{member}/dependents/{dependent}', [DependentController::class, 'destroy'])->name('members.dependents.destroy');

        Route::post('/members/{member}/amount-adjustments', [AmountAdjustmentController::class, 'store'])->name('members.amount-adjustments.store');
        Route::post('/members/{member}/amount-adjustments/revert-to-automatic', [AmountAdjustmentController::class, 'revertToAutomatic'])->name('members.amount-adjustments.revert-to-automatic');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->name('users.update-status');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');

        Route::get('/activity', [ActivityLogController::class, 'index'])->name('activity.index');

        Route::get('/data-quality', [DataQualityController::class, 'index'])->name('data-quality.index');
        Route::put('/benefit-periods/{benefitPeriod}', [BenefitPeriodController::class, 'update'])->name('benefit-periods.update');
    });

    // Must stay after the /members/{member}/... POST routes above,
    // since {member} would otherwise try to route-model-bind those literal segments.
    Route::get('/members/{member}', [MemberController::class, 'show'])->name('members.show');
});
