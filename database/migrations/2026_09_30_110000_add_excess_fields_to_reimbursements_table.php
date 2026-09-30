<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Automatic excess GHP, stored on the reimbursement itself.
     *
     *   available_ghp  the member's Available GHP for the OR date's coverage
     *                  period at the time of filing (NULL for records filed
     *                  before this existed -> shown as "—").
     *   excess_amount  max(reimbursement amount - available_ghp, 0), computed
     *                  by ReimbursementController on the server. 0 when the
     *                  claim is within the available balance.
     *
     * TRACKING / REPORTING ONLY: BenefitAccrualService never reads either
     * column, so GHP usage, Available GHP, the GHP amount, monthly GHP and
     * required amount are unaffected.
     *
     * One column per reimbursement means a reimbursement can never carry two
     * excess records. Existing rows get excess_amount = 0 and available_ghp
     * NULL (nothing is invented for history). The older `excess_deductions`
     * table (manually recorded, per deduction month) is NOT touched or
     * dropped — its rows stay as historical data.
     */
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            if (! Schema::hasColumn('reimbursements', 'available_ghp')) {
                $table->decimal('available_ghp', 12, 2)->nullable()->after('or_amount');
            }

            if (! Schema::hasColumn('reimbursements', 'excess_amount')) {
                $table->decimal('excess_amount', 12, 2)->default(0)->after('available_ghp');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            if (Schema::hasColumn('reimbursements', 'excess_amount')) {
                $table->dropColumn('excess_amount');
            }

            if (Schema::hasColumn('reimbursements', 'available_ghp')) {
                $table->dropColumn('available_ghp');
            }
        });
    }
};
