<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coverage Year History -> Reimbursement drill-down (see
     * BenefitPeriodController::reimbursements()). Reimbursements were
     * previously only ever associated with a coverage year implicitly, by
     * matching or_date against a benefit_periods date range on the fly
     * (see BenefitAccrualService::calculate()). That matching logic still
     * exists and is unchanged — this column is an explicit, reliable ID
     * link on top of it, so the Coverage Year History table can show
     * "only the reimbursements for this exact period" via a straight
     * foreign key instead of re-deriving the date range every time.
     *
     * nullOnDelete(): a reimbursement is never deleted (voided) when its
     * benefit_period_id disappears; it just loses the fast-path link and
     * falls back to being unlinked, same as any reimbursement filed
     * before this column existed.
     */
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->foreignId('benefit_period_id')
                ->nullable()
                ->after('member_id')
                ->constrained()
                ->nullOnDelete();
        });

        // Backfill: link every existing reimbursement to the benefit
        // period whose coverage range its or_date already falls inside,
        // for the same member. One-time data fix — from here on,
        // ReimbursementController sets this column directly whenever a
        // reimbursement is filed or edited.
        DB::statement(<<<'SQL'
            UPDATE reimbursements r
            INNER JOIN benefit_periods bp
                ON bp.member_id = r.member_id
               AND r.or_date BETWEEN bp.from_date AND bp.to_date
            SET r.benefit_period_id = bp.id
            WHERE r.benefit_period_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('benefit_period_id');
        });
    }
};
