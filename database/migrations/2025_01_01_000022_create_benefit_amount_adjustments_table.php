<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NEW table — did not exist in the legacy system. Turns the free-text
     * audit trail previously buried in t_member.c_remarks (e.g. "GHP amount
     * changed from 4200 to 26775 with IT request form dtd 2019-05-21...")
     * into structured, queryable history. See migration analysis doc §2.3/2.4.
     */
    public function up(): void
    {
        Schema::create('benefit_amount_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->decimal('old_amount', 12, 2);
            $table->decimal('new_amount', 12, 2);
            $table->text('reason')->nullable();
            $table->string('request_reference')->nullable(); // e.g. "IT Request Form", "IT email"
            $table->date('requested_at')->nullable();
            $table->string('recorded_by')->nullable();        // e.g. "Ms. Rhea" — free text from legacy remarks
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_amount_adjustments');
    }
};
