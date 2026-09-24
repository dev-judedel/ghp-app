<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email Account field for the Member Management enhancement. Nullable
     * at the DB level (existing ~594 legacy members don't have one and
     * shouldn't be broken by this migration) — required going forward only
     * at the application layer, for new members (see StoreMemberRequest).
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
