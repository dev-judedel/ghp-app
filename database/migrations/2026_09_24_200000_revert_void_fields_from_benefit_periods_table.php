<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reverts 2026_09_24_100000_add_void_fields_to_benefit_periods_table —
     * the Void/Delete/Search/Filter feature on Benefit Period Year History
     * has been removed per client request, in favor of a strict
     * one-generation-per-GHP-cycle rule with no way to undo a generation.
     *
     * Restores the original unique(member_id, from_date, to_date)
     * constraint, which IS the strict rule at the database level: once a
     * cycle's period exists, no second row for that same member+cycle can
     * ever be inserted, full stop — no void, no delete, no regenerate.
     *
     * Data safety (see task.md's revert instructions — "do not delete
     * existing valid Coverage Year History records"): if any member was
     * actually voided-then-regenerated while this feature was live, that
     * member now has TWO rows sharing the same member_id/from_date/to_date
     * (one is_voided=1, one is_voided=0) — restoring the unique
     * constraint would fail on that pair. Rather than leave the database
     * unable to migrate, or blindly delete rows, this only removes a
     * voided row when an ACTIVE (non-voided) sibling for the exact same
     * member+cycle also exists — i.e. only rows that were genuinely
     * superseded by a regeneration. A voided row with no active sibling
     * (voided, never regenerated) is left completely untouched — it just
     * becomes an ordinary row once is_voided is dropped below, which is
     * the correct outcome under the new strict rule: that cycle already
     * has a generated period on file, so it stays blocked from
     * regeneration, exactly as if Void had never existed. If some other
     * ambiguous duplicate exists (e.g. two rows both still voided, no
     * active row to prefer), it's deliberately left alone rather than
     * guessed at — the unique-constraint step below will then fail
     * loudly with a clear DB error instead of silently discarding data,
     * so a human can look at that specific case.
     */
    public function up(): void
    {
        if (Schema::hasColumn('benefit_periods', 'is_voided')) {
            $duplicateGroups = DB::table('benefit_periods')
                ->select('member_id', 'from_date', 'to_date')
                ->groupBy('member_id', 'from_date', 'to_date')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicateGroups as $group) {
                $rows = DB::table('benefit_periods')
                    ->where('member_id', $group->member_id)
                    ->whereDate('from_date', $group->from_date)
                    ->whereDate('to_date', $group->to_date)
                    ->get();

                $hasActiveSibling = $rows->contains(fn ($row) => ! $row->is_voided);

                if ($hasActiveSibling) {
                    DB::table('benefit_periods')
                        ->where('member_id', $group->member_id)
                        ->whereDate('from_date', $group->from_date)
                        ->whereDate('to_date', $group->to_date)
                        ->where('is_voided', true)
                        ->delete();
                }
            }
        }

        // Add the unique index back FIRST (same FK-backing-index ordering
        // lesson as the original void migration) — only if it isn't
        // already there, i.e. only if this hasn't already been reverted.
        if (! $this->hasIndex('benefit_periods', 'benefit_periods_member_id_from_date_to_date_unique')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->unique(['member_id', 'from_date', 'to_date'], 'benefit_periods_member_id_from_date_to_date_unique');
            });
        }

        if ($this->hasIndex('benefit_periods', 'benefit_periods_member_from_to_index')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->dropIndex('benefit_periods_member_from_to_index');
            });
        }

        Schema::table('benefit_periods', function (Blueprint $table) {
            if (Schema::hasColumn('benefit_periods', 'voided_by')) {
                $table->dropConstrainedForeignId('voided_by');
            }

            $columnsToDrop = array_values(array_intersect(
                ['is_voided', 'voided_at', 'voided_reason'],
                Schema::getColumnListing('benefit_periods')
            ));

            if ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Not reversible in the "recreate exactly what void() deleted" sense —
     * the duplicate-cleanup step above is intentionally one-way (see the
     * up() docblock). Rolling back just restores the void columns/index
     * shape; it does not resurrect any voided rows that were removed as
     * confirmed duplicates.
     */
    public function down(): void
    {
        Schema::table('benefit_periods', function (Blueprint $table) {
            if (! Schema::hasColumn('benefit_periods', 'is_voided')) {
                $table->boolean('is_voided')->default(false)->after('remarks');
            }
            if (! Schema::hasColumn('benefit_periods', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('is_voided');
            }
            if (! Schema::hasColumn('benefit_periods', 'voided_reason')) {
                $table->text('voided_reason')->nullable()->after('voided_at');
            }
            if (! Schema::hasColumn('benefit_periods', 'voided_by')) {
                $table->foreignId('voided_by')->nullable()->after('voided_reason')->constrained('users')->nullOnDelete();
            }
        });

        if (! $this->hasIndex('benefit_periods', 'benefit_periods_member_from_to_index')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->index(['member_id', 'from_date', 'to_date'], 'benefit_periods_member_from_to_index');
            });
        }

        if ($this->hasIndex('benefit_periods', 'benefit_periods_member_id_from_date_to_date_unique')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->dropUnique('benefit_periods_member_id_from_date_to_date_unique');
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))->contains('name', $indexName);
    }
};
