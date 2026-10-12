<?php

namespace App\Services;

use App\Http\Middleware\EnsureMemberIsActive;
use App\Models\BenefitAmountAdjustment;
use App\Models\ExcessCoverage;
use App\Models\Member;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The ONLY place a GHP amount adjustment (and reverting one) is applied.
 * Mirrors DependentEligibilityService's shape on purpose: a manual GHP
 * override is treated as just another fund-increasing event, reusing the
 * exact engine primitives a dependent becoming eligible already uses
 * (BenefitAccrualService::fundIncrease() / applyFundIncreaseToExcess() /
 * refreshCurrentPeriodIfAmountChanged()) — there is deliberately one
 * calculation, not two (task.md §2.29).
 *
 * The one thing specific to an adjustment is that its effect on Excess GHP
 * is tracked per-claim (ExcessCoverage, keyed by benefit_amount_adjustment_id)
 * so it can be undone later by exactly what it covered, never by
 * re-deriving it from whatever the balances happen to be at revert time —
 * other things (a new claim, a dependent, another adjustment) may have
 * happened in between.
 */
class AmountAdjustmentService
{
    public function __construct(private readonly BenefitAccrualService $accrual)
    {
    }

    /**
     * Sets a new GHP amount (always a manual override — see
     * Member::ghp_amount_is_manual) and, exactly like a dependent becoming
     * eligible, applies whatever that actually increases the fund by
     * toward the member's outstanding Excess GHP first — oldest claim
     * first — before any of it reaches Available GHP. A decrease (new
     * amount lower than old) never covers anything. Never generates a
     * benefit period that doesn't already exist (same rule "Adjust GHP
     * amount" has always followed).
     */
    public function apply(Member $member, float $newAmount, ?string $reason, ?string $requestReference, ?string $requestedAt, User $by): BenefitAmountAdjustment
    {
        return DB::transaction(function () use ($member, $newAmount, $reason, $requestReference, $requestedAt, $by) {
            $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
            $this->assertMemberIsActive($locked);

            $oldAmount = (float) $locked->ghp_amount;
            $asOf = Carbon::now();

            [$from, $to] = $this->accrual->coveragePeriod($locked, $asOf);

            // Snapshot BEFORE anything changes — a later revert must restore
            // exactly this, not whatever the figure happens to be by then
            // (task.md §2.29, "never rebuild from current balances only").
            // Only records an increase can actually cover: a CLAIMED record is
            // locked (task.md §2.32), so its excess is not "outstanding" for this
            // purpose and must not inflate the snapshot / the remaining figure.
            $outstandingBefore = round((float) $locked->reimbursements()
                ->notVoided()
                ->unclaimed()
                ->whereBetween('or_date', [$from, $to])
                ->sum('excess_amount'), 2);

            // The cycle's OPEN record (the latest Unclaimed one), else its latest record. A cycle can
            // hold several records (task.md §2.35) and a Claimed one is frozen, so "->first()" would
            // pick record 1 with a stale stored amount and overstate the increase.
            $period = $locked->benefitPeriods()
                ->whereDate('from_date', $from->toDateString())
                ->whereDate('to_date', $to->toDateString())
                ->orderByRaw('CASE WHEN claimed_at IS NULL THEN 0 ELSE 1 END')
                ->orderByDesc('record_no')
                ->first();

            if ($period !== null && $period->isClaimed()) {
                // Every record is Claimed: its stored amount may predate earlier adjustments, so the
                // member's amount just before THIS change is the true "before" (in-memory copy only).
                $period = $period->replicate();
                $period->ghp_amount = $oldAmount;
            }

            // This is what makes the override stick — see BenefitAccrualService::
            // resolveGhpAmount(). Without ghp_amount_is_manual, the next
            // dependent change or "Generate benefit period" click would
            // silently recalculate this back to 3600/4200.
            $locked->update([
                'ghp_amount' => $newAmount,
                'ghp_amount_is_manual' => true,
            ]);

            $adjustment = new BenefitAmountAdjustment();
            $adjustment->member_id = $locked->id;
            // forceFill(), not create()/fill(): kind, outstanding_excess_before
            // and the other tracking columns are deliberately excluded from
            // #[Fillable] on this model (see BenefitAmountAdjustment's own
            // docblock) so a request can never set them directly — this
            // service is the one trusted place that's allowed to.
            $adjustment->forceFill([
                'kind' => BenefitAmountAdjustment::KIND_ADJUSTMENT,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'outstanding_excess_before' => $outstandingBefore,
                'reason' => $reason,
                'request_reference' => $requestReference,
                'requested_at' => $requestedAt ?? $asOf->toDateString(),
                'recorded_by' => $by->name,
            ])->save();

            // Captured BEFORE the refresh below changes the period's stored
            // amount — fundIncrease() compares against the period's CURRENT
            // (pre-refresh) ghp_amount, so this must run first. $locked already
            // has the new amount + manual flag, so this is the real increase.
            $increase = $period !== null ? $this->accrual->fundIncrease($locked, $period, $asOf) : null;

            $locked->load('dependents', 'reimbursements', 'benefitPeriods');
            $this->refreshCurrentPeriod($locked, $asOf, $adjustment);

            // Read back what was actually applied rather than re-deriving it,
            // so this row's own figures and the ExcessCoverage rows it owns
            // can never disagree (task.md §2.29, prevent-double-application).
            $covered = round((float) $adjustment->coverages()->sum('amount'), 2);

            $adjustment->forceFill([
                'increase_amount' => $increase !== null ? round($increase, 2) : null,
                'excess_covered_amount' => $covered,
                'excess_remaining_after' => $increase !== null ? round($outstandingBefore - $covered, 2) : null,
                // Never negative: a DECREASE adds nothing to Available GHP
                // (increase_amount itself keeps the signed figure).
                'available_increase_amount' => $increase !== null ? max(round($increase - $covered, 2), 0) : null,
            ])->save();

            return $adjustment->refresh();
        });
    }

    /**
     * Reverts a member from a manual GHP amount override back to the
     * automatic figure (3,600/4,200 based on current dependents) — the
     * only revert this app's single-current-override architecture
     * supports (Member has exactly one ghp_amount / ghp_amount_is_manual,
     * not a stack). Sequential adjustments are each logged as their own
     * row, but "Revert to automatic" undoes the whole override chain at
     * once, consistent with what flipping ghp_amount_is_manual back to
     * false and recomputing from dependents already does to the amount
     * itself — so this reverses EVERY not-yet-reversed excess_coverages
     * row for the member, not just the most recent adjustment's.
     *
     * Per claim: excess_amount is restored by exactly what the covering
     * row(s) added, excess_covered_amount reduced by the same — read from
     * the ledger, never recomputed from current balances (a new claim or
     * another change may have happened since — task.md §2.29). "Available
     * GHP" needs no separate bookkeeping to reverse: it is always derived
     * from ghp_amount + reimbursements, never stored as a delta, so
     * recomputing it from the (now lower, automatic) ghp_amount via
     * accrue() already removes exactly what the override had added — the
     * same "recompute, don't increment" principle BenefitAccrualService
     * documents throughout.
     *
     * @return BenefitAmountAdjustment|string the revert's history row, or a message string when there was nothing to revert
     */
    public function revertToAutomatic(Member $member, User $by): BenefitAmountAdjustment|string
    {
        return DB::transaction(function () use ($member, $by) {
            $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
            $this->assertMemberIsActive($locked);

            if (! $locked->ghp_amount_is_manual) {
                return 'This member is already on the automatic GHP amount.';
            }

            $oldAmount = (float) $locked->ghp_amount;
            $asOf = Carbon::now();

            $coverages = ExcessCoverage::where('member_id', $locked->id)
                ->whereNull('reversed_at')
                ->with('reimbursement')
                ->lockForUpdate()
                ->get();

            // A Claimed record is frozen (task.md §2.32): giving excess back to it would
            // rewrite a locked record after the fact. Refused BEFORE anything is written.
            if ($message = $this->claimedCoverageMessage($coverages)) {
                return $message;
            }

            $totalReversed = $this->restoreCoverages($coverages, $asOf);

            BenefitAmountAdjustment::where('member_id', $locked->id)
                ->where('kind', BenefitAmountAdjustment::KIND_ADJUSTMENT)
                ->whereNull('reverted_at')
                ->update(['reverted_at' => $asOf, 'reverted_by' => $by->id]); // query-builder update: bypasses $fillable entirely (not Eloquent mass assignment)

            $locked->update(['ghp_amount_is_manual' => false]);
            $locked->unsetRelation('dependents');
            $locked->load('dependents');
            $newAmount = $this->accrual->resolveGhpAmount($locked);
            $locked->update(['ghp_amount' => $newAmount]);

            $revert = new BenefitAmountAdjustment();
            $revert->member_id = $locked->id;
            $revert->forceFill([
                'kind' => BenefitAmountAdjustment::KIND_REVERT,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                // Repurposed for a revert row: the amount of excess GHP
                // RESTORED (given back), not covered. The UI labels this
                // field differently based on kind — see members/show.blade.php.
                'excess_covered_amount' => $totalReversed > 0 ? $totalReversed : null,
                'reason' => 'Reverted to automatic (based on current dependents)',
                'request_reference' => null,
                'requested_at' => $asOf->toDateString(),
                'recorded_by' => $by->name,
            ])->save();

            $locked->load('dependents', 'reimbursements', 'benefitPeriods');
            $this->accrual->refreshCurrentPeriodIfGenerated($locked, $asOf);

            return $revert->refresh();
        });
    }

    /**
     * Reverts ONE adjustment — the member's most recent active one — and
     * nothing else (task.md §2.31). Where "Revert to automatic" undoes the
     * whole override chain, this undoes only this adjustment's own effect:
     *
     *   - only THIS adjustment's not-yet-reversed ledger rows are restored to
     *     their claims (excess_amount += what it covered, excess_covered_amount
     *     -= the same), read from the ledger — never "current + adjustment";
     *     any earlier adjustment's cover, a dependent-driven cover and every
     *     claim filed since are left exactly as they are;
     *   - the member's amount goes back to what it was BEFORE this adjustment
     *     (its old_amount). If it was the only active adjustment that is the
     *     automatic amount, so the override is released and the amount is
     *     recomputed from the current dependents; otherwise the member stays
     *     on the earlier adjustment's manual amount;
     *   - Available GHP needs no delta: it is always derived from the amount
     *     and the reimbursements, so recomputing the period removes exactly the
     *     increase this adjustment had added (whatever part had reached
     *     Available), and Used GHP and every reimbursement amount are untouched.
     *
     * Only the LATEST active adjustment can be reverted: each adjustment's
     * old_amount is the previous one's new_amount, so reverting an earlier one
     * underneath a later one has no defined result. That is refused with a
     * message (revert the later one first, or use Revert to automatic for
     * all of them) rather than guessing.
     *
     * One transaction; the member and the adjustment rows are locked and the
     * state is re-checked under the lock, so a double click or a second
     * administrator can't revert it twice.
     *
     * @return BenefitAmountAdjustment|string the revert's history row, or the reason it was refused
     */
    public function revertAdjustment(Member $member, BenefitAmountAdjustment $adjustment, User $by): BenefitAmountAdjustment|string
    {
        return DB::transaction(function () use ($member, $adjustment, $by) {
            $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
            $this->assertMemberIsActive($locked);

            $target = BenefitAmountAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

            if ($target->member_id !== $locked->id) {
                throw new RuntimeException('That adjustment does not belong to this member.');
            }

            if (! $target->isAdjustment()) {
                return 'Only a GHP amount adjustment can be reverted.';
            }

            if ($target->isReverted()) {
                return 'This GHP amount adjustment has already been reverted — nothing was changed.';
            }

            $activeAdjustments = BenefitAmountAdjustment::where('member_id', $locked->id)
                ->where('kind', BenefitAmountAdjustment::KIND_ADJUSTMENT)
                ->whereNull('reverted_at')
                ->lockForUpdate()
                ->get();

            if ($activeAdjustments->contains(fn ($a) => $a->id > $target->id)) {
                return 'Only the most recent active GHP amount adjustment can be reverted. Revert the later adjustment first, or use "Revert to automatic" to undo all of them.';
            }

            $asOf = Carbon::now();
            $currentAmount = (float) $locked->ghp_amount;

            $coverages = ExcessCoverage::where('benefit_amount_adjustment_id', $target->id)
                ->whereNull('reversed_at')
                ->with('reimbursement')
                ->lockForUpdate()
                ->get();

            // Same freeze as above: never restore excess onto a Claimed (locked) record.
            if ($message = $this->claimedCoverageMessage($coverages)) {
                return $message;
            }

            $restored = $this->restoreCoverages($coverages, $asOf);

            $target->forceFill(['reverted_at' => $asOf, 'reverted_by' => $by->id])->save();

            if ($activeAdjustments->count() > 1) {
                // An earlier adjustment is still active: back to the amount in
                // force before this one. The manual override stays.
                $locked->update(['ghp_amount' => (float) $target->old_amount]);
                $newAmount = (float) $target->old_amount;
            } else {
                // That was the only active adjustment: release the override.
                $locked->update(['ghp_amount_is_manual' => false]);
                $locked->unsetRelation('dependents');
                $locked->load('dependents');
                $newAmount = $this->accrual->resolveGhpAmount($locked);
                $locked->update(['ghp_amount' => $newAmount]);
            }

            $revert = new BenefitAmountAdjustment();
            $revert->member_id = $locked->id;
            $revert->forceFill([
                'kind' => BenefitAmountAdjustment::KIND_REVERT,
                'old_amount' => $currentAmount,
                'new_amount' => $newAmount,
                // As on every revert row: the excess GHP RESTORED, not covered.
                'excess_covered_amount' => $restored > 0 ? $restored : null,
                'reason' => 'Reverted GHP adjustment of '.optional($target->requested_at)->format('M d, Y').' (₱'.number_format((float) $target->old_amount, 2).' → ₱'.number_format((float) $target->new_amount, 2).')',
                'request_reference' => null,
                'requested_at' => $asOf->toDateString(),
                'recorded_by' => $by->name,
            ])->save();

            $locked->load('dependents', 'reimbursements', 'benefitPeriods');
            $this->accrual->refreshCurrentPeriodIfGenerated($locked, $asOf);

            return $revert->refresh();
        });
    }

    /**
     * Gives each claim back exactly what the given ledger rows covered and
     * marks those rows reversed (never deleted). The ONE place that restore
     * happens, shared by "Revert to automatic" (every active row) and
     * revertAdjustment() (one adjustment's rows), so the two can't drift.
     * Amounts come from the ledger, never from current balances.
     *
     * @param  \Illuminate\Support\Collection<int, ExcessCoverage>  $coverages
     * @return float total excess GHP restored
     */
    private function restoreCoverages($coverages, Carbon $asOf): float
    {
        $total = 0.0;

        foreach ($coverages->groupBy('reimbursement_id') as $group) {
            $reimbursement = $group->first()->reimbursement; // ->withTrashed() on the relation itself
            $sumForThisClaim = round((float) $group->sum('amount'), 2);

            $reimbursement->update([
                'excess_amount' => round((float) $reimbursement->excess_amount + $sumForThisClaim, 2),
                'excess_covered_amount' => round(max((float) $reimbursement->excess_covered_amount - $sumForThisClaim, 0), 2),
            ]);

            $total = round($total + $sumForThisClaim, 2);
        }

        if ($coverages->isNotEmpty()) {
            ExcessCoverage::whereIn('id', $coverages->pluck('id'))->update(['reversed_at' => $asOf]);
        }

        return $total;
    }

    /**
     * A reimbursement record that was covered by an adjustment and has SINCE been
     * Claimed is locked: reverting the adjustment would hand excess back to it and
     * change a finalized record. So the revert is refused (nothing written) until
     * that record's claim is reverted first. Null when every record involved is
     * still unlocked.
     *
     * @param  \Illuminate\Support\Collection<int, ExcessCoverage>  $coverages
     */
    private function claimedCoverageMessage($coverages): ?string
    {
        $locked = $coverages->first(fn ($coverage) => $coverage->reimbursement?->isLocked());

        if ($locked === null) {
            return null;
        }

        return 'This GHP adjustment covered excess on a reimbursement record that has since been Claimed and locked'
            .($locked->reimbursement->or_no ? ' (OR '.$locked->reimbursement->or_no.')' : '')
            .'. Revert that record\'s claim first, then revert the adjustment — a Claimed record is never changed after the fact. Nothing was changed.';
    }

    /**
     * Defense in depth: the routes are already behind 'member.active'
     * (EnsureMemberIsActive), but this service is the one place an adjustment
     * is applied, so a deactivated member can't be adjusted or reverted by any
     * other caller either. Checked on the LOCKED row, never the stale model.
     *
     * @throws RuntimeException
     */
    private function assertMemberIsActive(Member $locked): void
    {
        if (! $locked->is_active) {
            throw new RuntimeException(EnsureMemberIsActive::MESSAGE);
        }
    }

    /**
     * Keeps an ALREADY-generated current period in step with the new
     * amount. Deliberately never creates one — adjusting the GHP amount
     * must not generate the benefit period as a side effect.
     */
    private function refreshCurrentPeriod(Member $member, Carbon $asOf, BenefitAmountAdjustment $source): void
    {
        if ($member->is_active && $member->deduction_start_date !== null) {
            $this->accrual->refreshCurrentPeriodIfAmountChanged($member, $asOf, $source);
        }
    }
}
