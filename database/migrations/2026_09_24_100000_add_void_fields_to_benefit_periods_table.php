<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a Void action to Benefit Period Year History (member page ->
     * "Benefit periods" card), mirroring the void pattern already used on
     * reimbursements (see 2025_01_01_000028_add_void_fields_to_reimbursements_table).
     * Voiding keeps the row intact for audit/history but excludes it from
     * counting as the cycle's active period. Unlike reimbursements, a
     * voided benefit period has no "unvoid" — the only ways forward are
     * Delete (while still voided) or generating a fresh active row for
     * the same cycle.
     *
     * The original unique(['member_id','from_date','to_date']) constraint
     * is dropped here: it assumed exactly one row could ever exist per
     * member+cycle, which Void breaks on purpose — a voided row and a
     * later regenerated active row now legitimately share the same dates.
     * "Only one ACTIVE row per member+cycle" is enforced at the
     * application layer instead, inside a locked transaction — the same
     * pattern MemberController::generateBenefitPeriod() and
     * ReimbursementController::assertWithinBalance() already use for
     * comparable invariants.
     *
     * IMPORTANT — index ordering: MySQL was silently using that unique
     * index as the backing index for the member_id foreign key (its
     * leftmost column), since no other index on member_id existed. Adding
     * the replacement plain index FIRST (in its own Schema::table() call,
     * so it lands in a separate ALTER TABLE before the drop) gives the FK
     * something to stand on the moment the unique index goes away —
     * dropping it first (or in the same statement) fails with MySQL error
     * 1553 ("needed in a foreign key constraint").
     */
    public function up(): void
    {
        Schema::table('benefit_periods', function (Blueprint $table) {
            $table->boolean('is_voided')->default(false)->after('remarks');
            $table->timestamp('voided_at')->nullable()->after('is_voided');
            $table->text('voided_reason')->nullable()->after('voided_at');
            $table->foreignId('voided_by')->nullable()->after('voided_reason')->constrained('users')->nullOnDelete();

            $table->index(['member_id', 'from_date', 'to_date'], 'benefit_periods_member_from_to_index');
        });

        Schema::table('benefit_periods', function (Blueprint $table) {
            $table->dropUnique('benefit_periods_member_id_from_date_to_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('benefit_periods', function (Blueprint $table) {
            // Same ordering concern in reverse: put the unique index back
            // (it can serve as the FK's backing index again) before
            // dropping the plain one that's replacing it.
            $table->unique(['member_id', 'from_date', 'to_date'], 'benefit_periods_member_id_from_date_to_date_unique');
        });

        Schema::table('benefit_periods', function (Blueprint $table) {
            $table->dropIndex('benefit_periods_member_from_to_index');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['is_voided', 'voided_at', 'voided_reason']);
        });
    }
};
