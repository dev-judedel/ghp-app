<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was t_avp_vp (undocumented in the original codebase — discovered in the
     * live dump). 76 rows: agent code -> name -> position (VP-MKTG / AVP-MKTG),
     * used only by the agent reimbursement report. Kept as its own table for
     * now since the legacy code keys it by name-matching rather than by
     * member code reliably; revisit linking directly to members.id later.
     */
    public function up(): void
    {
        Schema::create('agent_positions', function (Blueprint $table) {
            $table->id();
            $table->string('member_code'); // legacy c_code — not FK'd yet, see note above
            $table->string('name');
            $table->string('position'); // 'VP-MKTG' | 'AVP-MKTG'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_positions');
    }
};
