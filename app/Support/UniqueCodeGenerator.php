<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class UniqueCodeGenerator
{
    /**
     * Generates a PREFIX-###### code and re-rolls on collision, checking the
     * given table/column directly (not an Eloquent model) so this stays
     * usable from anywhere — models, migrations, controllers — without
     * pulling in model-specific concerns.
     */
    public static function generate(string $table, string $column, string $prefix = 'ALSC-'): string
    {
        do {
            $code = $prefix.random_int(100000, 999999);
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }
}
