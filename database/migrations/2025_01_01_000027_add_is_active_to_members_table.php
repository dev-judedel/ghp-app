<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NEW column — did not exist in the legacy system, which had no
     * active/inactive concept (terminated members were just deleted
     * outright, per migration analysis doc §2.3's orphaned-records finding).
     * Defaults to true so the historical import isn't affected.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('old_code');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
