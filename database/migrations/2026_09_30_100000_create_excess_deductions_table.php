<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Excess deductions: the part of an ACTUAL payroll deduction that is more
     * than the REQUIRED monthly GHP deduction for a benefit period
     * (required 300, actual 500 -> excess 200).
     *
     * A separate, tracking-only table on purpose. Nothing in the GHP
     * calculation (BenefitAccrualService: accrued amount, used, available,
     * carry-forward) reads it, so an excess deduction can never change GHP
     * usage, the available balance, the GHP amount, the monthly GHP or the
     * required amount. No existing table or row is touched.
     *
     * required_amount is a snapshot of the period's monthly GHP at the time
     * of recording, so later changes to the period never rewrite history.
     *
     * Duplicate protection (database level):
     *   - one record per member + benefit period + deduction month
     *   - a reimbursement can be linked to at most one excess deduction
     *     (reimbursement_id is NULL for unlinked ones; MySQL allows many NULLs
     *     in a unique index)
     */
    public function up(): void
    {
        Schema::create('excess_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('benefit_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('reimbursement_id')->nullable()->constrained()->nullOnDelete();
            $table->date('deduction_month');
            $table->decimal('required_amount', 12, 2);
            $table->decimal('actual_deduction', 12, 2);
            $table->decimal('excess_amount', 12, 2);
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['member_id', 'benefit_period_id', 'deduction_month'], 'excess_deductions_member_period_month_unique');
            $table->unique('reimbursement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excess_deductions');
    }
};
