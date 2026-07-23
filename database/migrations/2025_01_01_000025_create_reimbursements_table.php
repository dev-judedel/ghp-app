<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was t_reimbursement. 5,056 rows — c_or_no/c_hospital_name/c_description
     * are blank on most rows in practice; kept nullable rather than required.
     */
    public function up(): void
    {
        Schema::create('reimbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('or_no')->nullable();
            $table->date('or_date');
            $table->decimal('or_amount', 12, 2);
            $table->string('hospital_name')->nullable();
            $table->text('description')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('or_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursements');
    }
};
