<?php

use App\Http\Controllers\Admin\MemberBulkActionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ReimbursementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/members', [MemberController::class, 'index'])->name('members.index');

    Route::middleware('admin')->group(function () {
        Route::post('/members/bulk-action', [MemberBulkActionController::class, 'store'])->name('members.bulk-action');
        Route::post('/members', [MemberController::class, 'store'])->name('members.store');
        Route::post('/members/{member}/generate-benefit-period', [MemberController::class, 'generateBenefitPeriod'])->name('members.generate-benefit-period');
        Route::post('/members/{member}/reimbursements', [ReimbursementController::class, 'store'])->name('members.reimbursements.store');
    });

    // Must stay after the /members/{member}/... POST routes above,
    // since {member} would otherwise try to route-model-bind those literal segments.
    Route::get('/members/{member}', [MemberController::class, 'show'])->name('members.show');
});
