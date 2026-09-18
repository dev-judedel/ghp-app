<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was t_member. 594 rows in the live dump.
     */
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();               // c_code — e.g. '20068', 'T005', 'A083'
            $table->unsignedTinyInteger('member_type');       // c_mem_type — 0 = Employee, 1 = Agent
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('address')->nullable();
            $table->date('birthdate')->nullable();
            $table->unsignedTinyInteger('civil_status')->nullable(); // c_civil_stat — 0 = Single, 1 = Married
            $table->date('apply_date')->nullable();
            $table->date('deduction_start_date')->nullable(); // c_ded_start
            $table->decimal('ghp_amount', 12, 2)->default(0); // c_ghp_amt — NOT always 3600/4200, see benefit_amount_adjustments
            $table->text('remarks')->nullable();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('old_code')->nullable();           // c_old_code — legacy ID before a renumbering, kept for traceability
            $table->timestamps();
            $table->softDeletes();                            // legacy hard-deletes members; we keep history instead

            $table->index('last_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
