<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SUPERSEDED — this migration originally added the Void feature's
     * columns to benefit_periods. That feature has since been reverted
     * per client request (see the follow-up
     * 2026_09_24_200000_revert_void_fields_from_benefit_periods_table
     * migration, which undoes everything below).
     *
     * Rewritten to be fully idempotent rather than deleted, because its
     * FIRST run on the live DB failed partway through (MySQL error 1553,
     * dropping the old unique index) — and MySQL's ALTER TABLE statements
     * auto-commit individually, so the column/FK additions that ran
     * BEFORE that failure may already be sitting in the database even
     * though this migration was never recorded as completed. Every step
     * below checks first, so this safely finishes to a known state
     * (columns present, unique constraint dropped) no matter which of
     * "never ran", "partially ran", or "ran to completion after the fix"
     * the live database is currently in — which the revert migration
     * that follows it can then always assume as its starting point.
     */
    public function up(): void
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

    public function down(): void
    {
        Schema::table('benefit_periods', function (Blueprint $table) {
            $table->unique(['member_id', 'from_date', 'to_date'], 'benefit_periods_member_id_from_date_to_date_unique');
        });

        Schema::table('benefit_periods', function (Blueprint $table) {
            if ($this->hasIndex('benefit_periods', 'benefit_periods_member_from_to_index')) {
                $table->dropIndex('benefit_periods_member_from_to_index');
            }
            if (Schema::hasColumn('benefit_periods', 'voided_by')) {
                $table->dropConstrainedForeignId('voided_by');
            }
            $table->dropColumn(array_values(array_intersect(
                ['is_voided', 'voided_at', 'voided_reason'],
                Schema::getColumnListing('benefit_periods')
            )));
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))->contains('name', $indexName);
    }
};
