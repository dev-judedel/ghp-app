<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was t_dependents. 1,200 rows in the live dump.
     *
     * Legacy c_relation was freeform text with real-world variants: Son, Daughter,
     * Daugther/Dauther (typos), Child, Anak/Asawa (Tagalog), Spouse, Wife, Husband,
     * Father, Mother, Sister, Step Father, "Son - 2nd Child", "Youngest Daughter".
     * Using a plain string column here (not an enum) on purpose, to avoid failing
     * import on the messy legacy values — clean the data via a seeder/mapping table
     * before tightening this to an enum. See migration analysis doc §2.3.
     *
     * c_age is NOT carried over — computed on the fly from birthdate instead,
     * since the legacy stored value went stale between recalculations.
     */
    public function up(): void
    {
        Schema::create('dependents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('relation'); // see note above — tighten to enum after data cleanup
            $table->date('birthdate')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dependents');
    }
};
