<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was t_avail_used_ghp. 1,822 rows.
     * See note in create_benefit_periods_table migration re: possible future merge.
     */
    public function up(): void
    {
        Schema::create('benefit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('ghp_amount', 12, 2)->default(0);
            $table->decimal('available_amount', 12, 2)->default(0);
            $table->decimal('used_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['member_id', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_ledger');
    }
};
