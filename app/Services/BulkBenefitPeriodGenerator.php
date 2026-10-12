<?php

namespace App\Services;

use App\Models\BenefitPeriod;
use App\Models\Member;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk "Generate this year's benefit period" — ALL OR NOTHING.
 *
 * The rule (task.md §2.27): if even ONE selected member already has a
 * generated Benefit Period for their current coverage cycle — or is otherwise
 * not eligible — the ENTIRE batch is rejected and zero rows are written.
 * Nothing is silently skipped, and nothing is partially generated.
 *
 * "Already generated" means exactly what the single-member Generate button
 * (MemberController::generateBenefitPeriod()) means: a benefit_periods row for
 * this member whose from_date/to_date equal the member's CURRENT cycle, as
 * resolved by BenefitAccrualService::coveragePeriod($member, now()) — which
 * honors the member's configured Coverage year / End date (and falls back to
 * the standard Employee Apr–Mar / Agent Jun–May cycle). No month or year is
 * hardcoded here. That same (member_id, from_date, to_date) triple is what the
 * unique index on benefit_periods enforces, so this check and the database
 * constraint can never disagree.
 *
 * A previous cycle's period never blocks: it has different dates, so a
 * legitimately new cycle is generatable once it starts.
 *
 * Eligibility, same as the single button: the member must be active and have a
 * deduction_start_date. The bulk action used to skip such members silently;
 * they now block the batch and are listed, so the administrator always sees
 * exactly what was (not) processed.
 */
class BulkBenefitPeriodGenerator
{
    public function __construct(private readonly BenefitAccrualService $accrual)
    {
    }

    /**
     * Read-only pre-check used by the page BEFORE it asks the administrator to
     * confirm. Writes nothing. generate() repeats the same check on its own,
     * under row locks — this is for the notice only, never trusted by itself.
     *
     * @param  array<int, mixed>  $memberIds
     * @return array{existing: array<int, array>, ineligible: array<int, array>}
     */
    public function inspect(array $memberIds): array
    {
        $members = Member::whereIn('id', $this->normalizeIds($memberIds))->orderBy('id')->get();

        return $this->findBlockers($members, false);
    }

    /**
     * @param  array<int, mixed>  $memberIds
     * @return array{status: 'generated', count: int}
     *         |array{status: 'blocked', existing: array, ineligible: array}
     *         |array{status: 'failed', message: string}
     */
    public function generate(array $memberIds): array
    {
        $ids = $this->normalizeIds($memberIds);

        if ($ids === []) {
            return ['status' => 'failed', 'message' => 'No members were selected.'];
        }

        try {
            return DB::transaction(function () use ($ids) {
                // Locked in id order so two administrators submitting
                // overlapping batches queue up instead of deadlocking. The
                // loser waits here, then sees the winner's committed periods
                // in the check below (which also reads with a lock, i.e. the
                // latest committed data, not an older snapshot).
                $members = Member::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

                if ($members->count() !== count($ids)) {
                    return ['status' => 'failed', 'message' => 'One or more selected members no longer exist. No benefit periods were generated.'];
                }

                // 1) Check EVERY selected member first — nothing is inserted yet.
                $blockers = $this->findBlockers($members, true);

                if ($blockers['existing'] !== [] || $blockers['ineligible'] !== []) {
                    return ['status' => 'blocked'] + $blockers;
                }

                // 2) Only now generate, all inside this one transaction: any
                //    exception below rolls every insert back.
                foreach ($members as $member) {
                    $member->load('dependents', 'reimbursements', 'benefitPeriods');
                    $this->accrual->accrue($member);
                }

                return ['status' => 'generated', 'count' => $members->count()];
            });
        } catch (UniqueConstraintViolationException) {
            // Last-resort backstop: the unique index on (member_id, from_date,
            // to_date) rejected a row that slipped past the check. The
            // transaction has rolled back, so nothing from this batch exists.
            return ['status' => 'failed', 'message' => 'A benefit period for one of the selected members was generated at the same moment by another request. No benefit periods were generated — please review the selection and try again.'];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'failed', 'message' => 'An unexpected error stopped the generation. No benefit periods were generated.'];
        }
    }

    /**
     * @param  Collection<int, Member>  $members
     * @param  bool  $lock  read the periods with FOR UPDATE (only valid inside a transaction)
     * @return array{existing: array<int, array>, ineligible: array<int, array>}
     */
    private function findBlockers(Collection $members, bool $lock): array
    {
        $now = Carbon::now();
        $existing = [];
        $ineligible = [];

        foreach ($members as $member) {
            $who = [
                'id' => $member->id,
                'code' => $member->code,
                'name' => $member->full_name,
            ];

            if (! $member->is_active) {
                $ineligible[] = $who + ['reason' => 'Member is inactive.'];

                continue;
            }

            if ($member->deduction_start_date === null) {
                $ineligible[] = $who + ['reason' => 'No deduction start date on file.'];

                continue;
            }

            [$from, $to] = $this->accrual->coveragePeriod($member, $now);

            $query = BenefitPeriod::where('member_id', $member->id)
                ->whereDate('from_date', $from->toDateString())
                ->whereDate('to_date', $to->toDateString());

            if ($lock) {
                $query->lockForUpdate();
            }

            if ($query->exists()) {
                $existing[] = $who + [
                    'coverage_year' => $this->coverageLabel($from, $to),
                    'period' => $from->format('M d, Y').' – '.$to->format('M d, Y'),
                ];
            }
        }

        return ['existing' => $existing, 'ineligible' => $ineligible];
    }

    /** "2026–2027" for a cycle spanning two calendar years, "2026" for a calendar-year cycle. */
    private function coverageLabel(CarbonInterface $from, CarbonInterface $to): string
    {
        return $from->year === $to->year ? (string) $from->year : $from->year.'–'.$to->year;
    }

    /**
     * Integer ids only, no duplicates, stable order — so the same member
     * submitted twice is only ever processed once.
     *
     * @param  array<int, mixed>  $memberIds
     * @return array<int, int>
     */
    private function normalizeIds(array $memberIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), fn (int $id) => $id > 0)));
        sort($ids);

        return $ids;
    }
}
