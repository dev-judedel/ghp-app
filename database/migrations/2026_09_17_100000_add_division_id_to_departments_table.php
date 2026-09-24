<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Departments and Divisions were independent lookup tables (a Member
     * has its own separate division_id and department_id — see
     * create_members_table). This adds a real Department -> Division
     * relationship for the new Department Management card, so an admin can
     * see/assign which Division a Department sits under. Nullable: existing
     * departments aren't assigned to any Division until an admin sets one.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('division_id');
        });
    }
};
