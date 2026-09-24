<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * email backs StoreMemberRequest/UpdateMemberRequest and the CSV import
     * flow (MemberImportController). resignation_date is set when a member
     * is deactivated via the per-row Action column (see
     * MemberController::updateStatus() / UpdateMemberStatusRequest) and
     * cleared back to NULL on reactivation.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('code');
            $table->date('resignation_date')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['email', 'resignation_date']);
        });
    }
};
