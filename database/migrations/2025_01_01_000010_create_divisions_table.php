<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was t_division (c_mtype, c_division) — 6 known rows:
     * Employee: Manager, Supervisor, Rank and File, Guard
     * Agent:    VP-Mktg, AVP-Mktg
     */
    public function up(): void
    {
        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('member_type'); // 0 = Employee, 1 = Agent (matches t_member.c_mem_type)
            $table->string('name');
            $table->timestamps();

            $table->unique(['member_type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('divisions');
    }
};
