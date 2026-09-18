<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When true, BenefitAccrualService::resolveGhpAmount() uses
     * members.ghp_amount as-is instead of recalculating 3600/4200 from
     * dependents — this is what makes a manual amount adjustment (see
     * AmountAdjustmentController) actually stick through future benefit
     * period generation, rather than being silently overwritten the next
     * time dependents change or "Generate benefit period" is clicked.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('ghp_amount_is_manual')->default(false)->after('ghp_amount');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('ghp_amount_is_manual');
        });
    }
};
