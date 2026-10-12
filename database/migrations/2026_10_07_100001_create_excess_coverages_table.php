<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row = "this GHP amount adjustment covered ₱X of this
     * reimbursement's Excess GHP" — the reversal ledger that lets a GHP
     * amount adjustment's effect on Excess GHP be undone later by exactly
     * what it covered, never by recomputing from current balances alone
     * (other things — a new claim, a dependent, another adjustment — may
     * have happened since). See BenefitAccrualService::
     * applyFundIncreaseToExcess() ($source param) and App\Models\
     * ExcessCoverage.
     *
     * Tracking only: nothing in the GHP calculation reads this table. The
     * actual covered amount lives on reimbursements.excess_covered_amount
     * (the same column a dependent-driven increase already uses) — this
     * table only records WHICH adjustment put how much of it there, so it
     * can be taken back out again on revert. Rows are never deleted; a
     * reversal stamps reversed_at instead, same pattern as
     * reimbursements.is_voided/voided_at (keep the record, mark it undone).
     *
     * member_id is redundant with reimbursement.member_id /
     * adjustment.member_id but kept directly on the row so "all of this
     * member's coverage ledger" is a single indexed where(), not a join
     * through either parent — this table is read on every revert.
     */
    public function up(): void
    {
        Schema::create('excess_coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('reimbursement_id')->constrained()->restrictOnDelete();
            $table->foreignId('benefit_amount_adjustment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'reversed_at']);
            $table->index('reimbursement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excess_coverages');
    }
};
