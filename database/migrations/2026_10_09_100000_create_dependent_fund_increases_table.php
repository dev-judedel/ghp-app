<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row = "this dependent becoming eligible raised the benefit fund by X; of
 * that, Y covered outstanding Excess GHP (claim by claim, in `coverages`) and
 * the rest stayed in Available GHP".
 *
 * Why it exists: §2.21 applies a dependent-driven increase straight to
 * reimbursements.excess_covered_amount with nothing recording WHICH increase
 * put it there, so removing the dependent could not take it back out (a
 * documented limitation). This is the missing record. When the increase it
 * describes goes away, exactly the amounts stored here are given back to the
 * claims — never a figure re-derived from current balances.
 *
 * Tracking only: nothing in the GHP calculation reads this table. Rows are never
 * deleted; a reversal stamps reversed_at/reversed_by, which is also what stops a
 * second reversal. dependent_id is nullOnDelete (the dependent row is hard
 * deleted on removal) so dependent_name keeps the audit trail readable.
 * period_from / period_to say which coverage cycle's fund the increase belongs
 * to, so removing a dependent later never touches an earlier cycle's claims.
 *
 * Nothing is backfilled: increases that happened before this table existed have
 * no row and are not reversible (the same limitation as before, for history only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependent_fund_increases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('dependent_id')->nullable()->constrained('dependents')->nullOnDelete();
            $table->string('dependent_name')->nullable();
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('increase_amount', 12, 2);
            $table->decimal('outstanding_excess_before', 12, 2)->default(0);
            $table->decimal('excess_covered_amount', 12, 2)->default(0);
            $table->decimal('available_increase_amount', 12, 2)->default(0);
            $table->json('coverages')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dependent_fund_increases');
    }
};
