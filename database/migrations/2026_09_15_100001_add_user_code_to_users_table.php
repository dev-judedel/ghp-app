<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unique, system-generated identifier for the User Management module
     * (format: ALSC-######). Going forward this is always assigned by
     * User::generateUniqueUserCode() — the Administrator never types one in
     * (see StoreUserRequest, which has no user_code field at all).
     *
     * Existing accounts are backfilled below with the same format so every
     * user has a code immediately after this migration runs. This is done
     * with the query builder directly (not the Eloquent model) since
     * migrations shouldn't depend on app code that may change later.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('user_code')->nullable()->unique()->after('id');
        });

        $usedCodes = [];

        foreach (DB::table('users')->whereNull('user_code')->pluck('id') as $id) {
            do {
                $code = 'ALSC-'.random_int(100000, 999999);
            } while (in_array($code, $usedCodes, true) || DB::table('users')->where('user_code', $code)->exists());

            $usedCodes[] = $code;

            DB::table('users')->where('id', $id)->update(['user_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('user_code');
        });
    }
};
