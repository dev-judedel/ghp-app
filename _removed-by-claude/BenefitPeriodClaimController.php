<?php

namespace App\Http\Controllers;

use App\Models\BenefitPeriod;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Claimed / Unclaimed status of a Coverage Year History benefit period, and
 * the Claim / Revert actions on it.
 *
 * LIFECYCLE (the whole of it — no status column, no extra states):
 *   Unclaimed --Claim--> Claimed --Revert--> Unclaimed (marked Reverted)
 * A reverted period can be claimed again (that starts a fresh claim and clears
 * the Reverted marker); Revert is only ever possible while it is Claimed, so a
 * second Revert of the same claim is refused.
 *
 * What Claim sets, and therefore what Revert reverses: ONLY the status
 * columns (claimed_at / claimed_by). Claim does not read or change the GHP
 * amount, Used, Available, reimbursements, dependents or excess GHP, so there
 * is no balance, excess or transaction effect to unwind — and Revert, like
 * Claim, never touches any of them or any other benefit period. The period
 * row itself is never deleted: the history keeps the period, who reverted it
 * and when (reverted_at / reverted_by + an explicit activity-log entry).
 *
 * Generation of the period is deliberately NOT revertible here: the strict
 * one-period-per-cycle rule (task.md §2.13) removed void / delete /
 * regenerate, so an Unclaimed, never-claimed period has nothing to revert.
 *
 * The current state is re-checked on the server under a row lock inside a
 * transaction, so a double click, a stale page or a second administrator can
 * never double-claim or double-revert — the button state in the page is only
 * a convenience. Routes are admin-only and sit behind 'member.active', so a
 * deactivated member's periods are read-only like the rest of their records.
 */
class BenefitPeriodClaimController extends Controller
{
    public const NOTHING_TO_REVERT = 'This Benefit Period has not been claimed, so there is nothing to revert.';

    public const ALREADY_REVERTED = 'No further revert is available for this Benefit Period.';

    public function claim(Request $request, Member $member, BenefitPeriod $benefitPeriod): RedirectResponse
    {
        $this->assertBelongsToMember($member, $benefitPeriod);

        $changed = DB::transaction(function () use ($request, $benefitPeriod) {
            $period = BenefitPeriod::whereKey($benefitPeriod->id)->lockForUpdate()->firstOrFail();

            if ($period->isClaimed()) {
                return false;
            }

            $period->markClaimed($request->user());

            return true;
        });

        $label = $this->label($benefitPeriod);

        return redirect()->route('members.show', $member)->with('status', $changed
            ? "Benefit period {$label} marked as Claimed."
            : "Benefit period {$label} is already Claimed — nothing was changed.");
    }

    public function revert(Request $request, Member $member, BenefitPeriod $benefitPeriod): RedirectResponse
    {
        $this->assertBelongsToMember($member, $benefitPeriod);

        $label = $this->label($benefitPeriod);

        // Returns null on success, otherwise the reason it was refused. Any
        // exception rolls the whole transaction back: no partial revert.
        $refusal = DB::transaction(function () use ($request, $member, $benefitPeriod) {
            $period = BenefitPeriod::whereKey($benefitPeriod->id)->lockForUpdate()->firstOrFail();

            if (! $period->isClaimed()) {
                return $period->isReverted() ? self::ALREADY_REVERTED : self::NOTHING_TO_REVERT;
            }

            $by = $request->user();
            $previousStatus = 'Claimed';
            $claimedAt = $period->claimed_at;

            // The model's own dirty-field log would write two bare
            // "claimed_at / reverted_at changed" lines; suppress it and write
            // ONE explicit entry that says what happened and to what.
            $period->disableLogging();
            $period->markReverted($by);
            $period->enableLogging();

            activity('benefit_period')
                ->performedOn($period)
                ->causedBy($by)
                ->withProperties([
                    'action' => 'Revert Benefit Period',
                    'member_id' => $member->id,
                    'member_code' => $member->code,
                    'coverage_year' => $period->coverage_year ?? $period->from_date->year,
                    'benefit_period' => $period->from_date->toDateString().' to '.$period->to_date->toDateString(),
                    'previous_status' => $previousStatus,
                    'new_status' => 'Unclaimed (Reverted)',
                    'claimed_at' => optional($claimedAt)->toDateTimeString(),
                    'reverted_at' => $period->reverted_at->toDateTimeString(),
                    'reverted_by' => $by->id,
                ])
                ->log(sprintf(
                    'Benefit period %s – %s (Coverage Year %s) of %s reverted: Claimed → Unclaimed. No GHP balance, reimbursement or excess figure was changed.',
                    $period->from_date->format('M d, Y'),
                    $period->to_date->format('M d, Y'),
                    $period->coverage_year ?? $period->from_date->year,
                    $member->code,
                ));

            return null;
        });

        return redirect()->route('members.show', $member)->with('status', $refusal === null
            ? "Benefit period {$label} reverted — it is Unclaimed again. No GHP balance, reimbursement or excess figure was changed."
            : "Benefit period {$label}: {$refusal}");
    }

    /** A period id from another member's page must never be actionable here. */
    private function assertBelongsToMember(Member $member, BenefitPeriod $benefitPeriod): void
    {
        abort_unless($benefitPeriod->member_id === $member->id, 404);
    }

    private function label(BenefitPeriod $period): string
    {
        return $period->from_date->format('M Y').' – '.$period->to_date->format('M Y');
    }
}
