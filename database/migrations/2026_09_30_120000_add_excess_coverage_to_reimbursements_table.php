<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks how much of a reimbursement's excess GHP has since been covered
     * by a dependent-driven increase in the benefit fund.
     *
     *   original_excess_amount  the excess when the claim was filed / last
     *                           edited (amount - Available GHP at filing).
     *   excess_covered_amount   how much of it later increases have covered.
     *   excess_amount           (existing column) is now the REMAINING excess
     *                           = original - covered, so every existing red
     *                           marking, export and email keeps showing the
     *                           outstanding amount without changes.
     *
     * Existing rows: original = their current excess_amount, covered = 0, so
     * nothing about history changes. Tracking only — none of these columns is
     * a reimbursement or a contribution.
     */
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            if (! Schema::hasColumn('reimbursements', 'original_excess_amount')) {
                $table->decimal('original_excess_amount', 12, 2)->default(0)->after('excess_amount');
            }

            if (! Schema::hasColumn('reimbursements', 'excess_covered_amount')) {
                $table->decimal('excess_covered_amount', 12, 2)->default(0)->after('original_excess_amount');
            }
        });

        // One-time backfill: the excess recorded so far IS the original excess.
        DB::table('reimbursements')
            ->where('excess_amount', '>', 0)
            ->where('original_excess_amount', 0)
            ->update(['original_excess_amount' => DB::raw('excess_amount')]);
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            foreach (['excess_covered_amount', 'original_excess_amount'] as $column) {
                if (Schema::hasColumn('reimbursements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
