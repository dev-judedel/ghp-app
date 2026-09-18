<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resignation/Deactivation Date. Nullable — active members (and every
     * existing member row) simply don't have one. Set whenever a member is
     * deactivated via the per-row Deactivate action (with an admin-provided
     * date), cleared back to NULL on Reactivate.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->date('resignation_date')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('resignation_date');
        });
    }
};
