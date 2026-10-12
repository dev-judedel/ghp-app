<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several Benefit Period RECORDS per member per coverage cycle (task.md §2.35).
 *
 * Until now benefit_periods held exactly one row per member + coverage cycle,
 * enforced by UNIQUE (member_id, from_date, to_date). A row is now a "record":
 * it collects every reimbursement filed until it is CLAIMED (then it is locked),
 * and the next reimbursement filed after a claim opens a new record for the SAME
 * cycle. So:
 *
 *   record_no   1, 2, 3 ... within (member_id, from_date, to_date). Existing rows
 *               become record 1 (the column default), so nothing existing moves.
 *
 *   The unique key becomes (member_id, from_date, to_date, record_no): still
 *   impossible to create the same record twice (double click, two requests),
 *   but a cycle may now have records 1, 2, 3. The OLD three-column unique index
 *   is the only thing that stopped that, so it is dropped — AFTER the new one
 *   exists, because MySQL needs an index that starts with member_id for that
 *   foreign key at every moment. No other constraint is touched.
 *
 * NO NEW ENTITLEMENT: this migration adds no money anywhere. The GHP entitlement
 * of a cycle is still computed ONCE per cycle by BenefitAccrualService::calculate()
 * from the member's amount, months and reimbursements by OR date (not from the
 * rows), and each record stores only its own share of it (see accrue()). Reports
 * that read periods count a cycle once (BenefitPeriod::summarizeCycle()).
 *
 * CLAIM STATE lives on the record again (benefit_periods.claimed_at / claimed_by /
 * reverted_at / reverted_by, which already exist). A short-lived design that kept it
 * per reimbursement (reimbursements.claimed_at ...) is retired: those columns stay in
 * place as history and are no longer read or written. BACKFILL: a period becomes
 * Claimed if it has at least one active reimbursement and EVERY active reimbursement
 * of it was claimed (claimed_at = the latest of theirs). A period whose reimbursements
 * were only partly claimed keeps whatever state it already had — that mixed state
 * cannot be expressed per record and is left for an administrator to claim.
 *
 * Nothing is deleted or recreated, and no amount, link or history row changes.
 *
 * down(): restoring the old three-column unique index is only possible while every
 * cycle still has a single record; with several it fails rather than lose a record.
 *
 * Idempotent (index / column guards).
 */
return new class extends Migration
{
    private const NEW_INDEX = 'benefit_periods_member_cycle_record_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('benefit_periods', 'record_no')) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->unsignedSmallInteger('record_no')->default(1)->after('to_date');
            });
        }

        if (! $this->hasIndexNamed(self::NEW_INDEX)) {
            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->unique(['member_id', 'from_date', 'to_date', 'record_no'], self::NEW_INDEX);
            });
        }

        $old = $this->findOldCycleUniqueIndex();

        if ($old !== null) {
            Schema::table('benefit_periods', function (Blueprint $table) use ($old) {
                $table->dropUnique($old);
            });
        }

        $this->backfillClaimState();
    }

    public function down(): void
    {
        if (Schema::hasColumn('benefit_periods', 'record_no')) {
            $hasSeveral = DB::table('benefit_periods')
                ->select('member_id', 'from_date', 'to_date')
                ->groupBy('member_id', 'from_date', 'to_date')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($hasSeveral) {
                throw new RuntimeException('Cannot roll back: some coverage cycles already have several benefit period records, and the old one-per-cycle unique index would reject them.');
            }

            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->unique(['member_id', 'from_date', 'to_date']);
            });

            if ($this->hasIndexNamed(self::NEW_INDEX)) {
                Schema::table('benefit_periods', function (Blueprint $table) {
                    $table->dropUnique(self::NEW_INDEX);
                });
            }

            Schema::table('benefit_periods', function (Blueprint $table) {
                $table->dropColumn('record_no');
            });
        }
    }

    private function hasIndexNamed(string $name): bool
    {
        foreach (Schema::getIndexes('benefit_periods') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /** The legacy UNIQUE index on exactly (member_id, from_date, to_date), whatever it was named. */
    private function findOldCycleUniqueIndex(): ?string
    {
        foreach (Schema::getIndexes('benefit_periods') as $index) {
            if (! ($index['unique'] ?? false) || ($index['primary'] ?? false)) {
                continue;
            }

            if (array_values($index['columns'] ?? []) === ['member_id', 'from_date', 'to_date']) {
                return $index['name'];
            }
        }

        return null;
    }

    private function backfillClaimState(): void
    {
        if (! Schema::hasColumn('reimbursements', 'claimed_at')) {
            return;
        }

        DB::table('benefit_periods')->whereNull('claimed_at')->orderBy('id')->each(function ($period) {
            $active = DB::table('reimbursements')
                ->where('benefit_period_id', $period->id)
                ->where('is_voided', false)
                ->whereNull('deleted_at');

            $total = (clone $active)->count();

            if ($total === 0) {
                return;
            }

            $claimed = (clone $active)->whereNotNull('claimed_at');

            if ((clone $claimed)->count() !== $total) {
                return;
            }

            $latest = (clone $claimed)->orderByDesc('claimed_at')->first();

            DB::table('benefit_periods')->where('id', $period->id)->update([
                'claimed_at' => $latest->claimed_at,
                'claimed_by' => $latest->claimed_by,
                'reverted_at' => null,
                'reverted_by' => null,
            ]);
        });
    }
};
