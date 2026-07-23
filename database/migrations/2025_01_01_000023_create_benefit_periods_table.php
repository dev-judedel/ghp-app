<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was t_process. 3,360 rows — one row per member per coverage year.
     * Coverage year: Apr 1 - Mar 31 for Employees, Jun 1 - May 31 for Agents (hardcoded in legacy code).
     *
     * NOTE: kept separate from benefit_ledger (was t_avail_used_ghp) for this initial
     * scaffold to keep the data import 1:1 and low-risk. The migration analysis doc
     * flags these two as likely mergeable — revisit once real usage patterns are
     * confirmed with the business owner, rather than forcing a redesign during import.
     */
    public function up(): void
    {
        Schema::create('benefit_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('ghp_amount', 12, 2)->default(0);
            $table->decimal('ghp_available', 12, 2)->default(0);
            $table->decimal('ghp_used', 12, 2)->default(0);
            $table->unsignedTinyInteger('member_type');
            $table->text('remarks')->nullable();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['member_id', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_periods');
    }
};
