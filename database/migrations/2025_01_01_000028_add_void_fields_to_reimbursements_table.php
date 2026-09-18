<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voiding keeps the original reimbursement row intact and visible (for
     * audit purposes) but excludes it from the "used" calculation in
     * BenefitAccrualService::calculate(). This is deliberately NOT the same
     * as soft-delete (which hides the row from normal queries entirely) —
     * a void needs to stay visible with a reason attached.
     */
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->boolean('is_voided')->default(false)->after('remarks');
            $table->timestamp('voided_at')->nullable()->after('is_voided');
            $table->text('voided_reason')->nullable()->after('voided_at');
            $table->foreignId('voided_by')->nullable()->after('voided_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['is_voided', 'voided_at', 'voided_reason']);
        });
    }
};
