<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Department names and Division names are no longer unique (task.md §2.36):
     * two records may share a name and are told apart by their own `id`.
     *
     *   departments.name                 had  unique(name)
     *   divisions.(member_type, name)    had  unique(member_type, name)
     *
     * Only those two unique indexes are dropped — primary keys, the
     * departments.division_id / members.* foreign keys and every row are
     * untouched. A plain (non-unique) index replaces each one so name lookups
     * (CSV import, the Department form's legacy division-name path) stay fast.
     * Idempotent: safe to run again after a partial run.
     */
    public function up(): void
    {
        $this->makeNonUnique('departments', ['name'], 'departments_name_index');
        $this->makeNonUnique('divisions', ['member_type', 'name'], 'divisions_member_type_name_index');
    }

    /**
     * Restoring the unique rule is only possible while no two records share a
     * name; otherwise this refuses rather than deleting or renaming anything.
     */
    public function down(): void
    {
        $duplicateDepartments = DB::table('departments')->select('name')->groupBy('name')->havingRaw('COUNT(*) > 1')->exists();
        $duplicateDivisions = DB::table('divisions')->select('member_type', 'name')->groupBy('member_type', 'name')->havingRaw('COUNT(*) > 1')->exists();

        if ($duplicateDepartments || $duplicateDivisions) {
            throw new RuntimeException(
                'Cannot restore the unique name rule: duplicate department or division names exist. '
                .'Rename or remove the duplicates first (nothing was changed).'
            );
        }

        $this->makeUnique('departments', ['name'], 'departments_name_unique');
        $this->makeUnique('divisions', ['member_type', 'name'], 'divisions_member_type_name_unique');
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function makeNonUnique(string $table, array $columns, string $plainName): void
    {
        $indexes = Schema::getIndexes($table);
        $names = array_column($indexes, 'name');

        if (! in_array($plainName, $names, true)) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $plainName) {
                $blueprint->index($columns, $plainName);
            });
        }

        foreach ($indexes as $index) {
            if (! empty($index['unique']) && empty($index['primary']) && $index['columns'] === $columns) {
                $name = $index['name'];

                Schema::table($table, function (Blueprint $blueprint) use ($name) {
                    $blueprint->dropUnique($name);
                });
            }
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function makeUnique(string $table, array $columns, string $uniqueName): void
    {
        $indexes = Schema::getIndexes($table);

        $hasUnique = collect($indexes)->contains(
            fn ($index) => ! empty($index['unique']) && empty($index['primary']) && $index['columns'] === $columns
        );

        if (! $hasUnique) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $uniqueName) {
                $blueprint->unique($columns, $uniqueName);
            });
        }

        foreach ($indexes as $index) {
            if (empty($index['unique']) && empty($index['primary']) && $index['columns'] === $columns) {
                $name = $index['name'];

                Schema::table($table, function (Blueprint $blueprint) use ($name) {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }
};
