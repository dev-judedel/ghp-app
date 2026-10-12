<?php

namespace App\Http\Controllers;

use App\Models\BenefitPeriod;
use App\Models\Member;
use App\Services\BenefitAccrualService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Claim | Revert on ONE Benefit Period RECORD (task.md §2.35).
 *
 * A coverage period can hold several records for a member (record_no 1, 2, 3 ...).
 * A record collects every reimbursement filed until it is CLAIMED; Claim locks it,
 * and the next reimbursement filed opens a new record for the same period
 * (BenefitAccrualService::recordForFiling()).
 *
 *   Unclaimed --Claim--> Claimed (LOCKED) --Revert--> Unclaimed (marked Reverted)
 *
 * Everything is keyed by the record's own id — never by member + coverage year or
 * the period dates, which sibling records share. Claim and Revert touch only THIS
 * record's claim columns: no GHP amount, no reimbursement (none is moved, deleted
 * or re-linked), no excess figure, no dependent and no other record changes. Claim
 * does not deduct or count anything, so there is no double-counting to undo.
 *
 * REVERT keeps the "one open record per coverage period" invariant. Only the LATEST
 * record of a period can be reverted: reverting an earlier one while a later record
 * exists would leave two Unclaimed records competing for new filings. The reverted
 * record is open again; new reimbursements go into it (it is the period's open
 * record) until it is claimed again, and the Claimed records before it are untouched.
 *
 * COORDINATION with filing: both actions lock the MEMBER row first — the same lock
 * ReimbursementController::store() takes — and then the record. A claim and a
 * filing that arrive together therefore run one after the other: if the claim wins,
 * the filing opens the next record; if the filing wins, it joins this record before
 * it is locked. The state is re-checked under the lock, so a double click, a stale
 * page or a second administrator can never double-claim or double-revert; the
 * page's buttons are only a convenience. Admin-only and behind 'member.active', so a
 * deactivated member's records are read-only.
 */
class BenefitPeriodClaimController extends Controller
{
    public const NOTHING_TO_CLAIM = 'This Benefit Period record has no active reimbursement, so there is nothing to claim yet. Claim it after a reimbursement has been filed into it.';

    public const NOTHING_TO_REVERT = 'This Benefit Period record has not been claimed, so there is nothing to revert.';

    public const ALREADY_REVERTED = 'No further revert is available for this Benefit Period record.';

    public const LATER_RECORD_EXISTS = 'A later Benefit Period record exists for this coverage period. Revert the later record first — only the most recent record can be reverted, so a coverage period never has two open records.';

    public function claim(Request $request, Member $member, BenefitPeriod $benefitPeriod): RedirectResponse
    {
        abort_unless($benefitPeriod->member_id === $member->id, 404);

        $outcome = DB::transaction(function () use ($request, $member, $benefitPeriod) {
            Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
            $record = BenefitPeriod::whereKey($benefitPeriod->id)->lockForUpdate()->firstOrFail();

            if ($record->isClaimed()) {
                return 'already';
            }

            // A record with no active reimbursement has nothing to lock; claiming it would
            // only make the next filing open a needless extra record.
            if (! $record->reimbursements()->where('is_voided', false)->exists()) {
                return 'empty';
            }

            $record->disableLogging();
            $record->markClaimed($request->user());
            $record->enableLogging();

            activity('benefit_period')
                ->performedOn($record)
                ->causedBy($request->user())
                ->withProperties([
                    'action' => 'Claim Benefit Period Record',
                    'member_id' => $member->id,
                    'member_code' => $member->code,
                    'coverage_year' => $record->coverage_year ?? $record->from_date->year,
                    'record_no' => $record->record_no,
                    'benefit_period' => $record->from_date->toDateString().' to '.$record->to_date->toDateString(),
                    'new_status' => 'Claimed',
                ])
                ->log(sprintf('%s of %s claimed — now locked: it accepts no further reimbursements.', $this->label($record), $member->code));

            return 'ok';
        });

        $label = $this->label($benefitPeriod);

        return redirect()->route('members.show', $member)->with('status', match ($outcome) {
            'ok' => "{$label} marked as Claimed. It is now locked; the next reimbursement filed for this coverage period opens a new record.",
            'empty' => "{$label}: ".self::NOTHING_TO_CLAIM,
            default => "{$label} is already Claimed — nothing was changed.",
        });
    }

    public function revert(Request $request, Member $member, BenefitPeriod $benefitPeriod, BenefitAccrualService $accrualService): RedirectResponse
    {
        abort_unless($benefitPeriod->member_id === $member->id, 404);

        $label = $this->label($benefitPeriod);

        // Returns null on success, otherwise the reason it was refused. Any
        // exception rolls the whole transaction back: no partial revert.
        $refusal = DB::transaction(function () use ($request, $member, $benefitPeriod, $accrualService) {
            $lockedMember = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
            $record = BenefitPeriod::whereKey($benefitPeriod->id)->lockForUpdate()->firstOrFail();

            if (! $record->isClaimed()) {
                return $record->isReverted() ? self::ALREADY_REVERTED : self::NOTHING_TO_REVERT;
            }

            if ($record->hasLaterRecord()) {
                return self::LATER_RECORD_EXISTS;
            }

            $by = $request->user();
            $claimedAt = $record->claimed_at;

            // The model's own dirty-field log would write two bare
            // "claimed_at / reverted_at changed" lines; suppress it and write
            // ONE explicit entry that says what happened and to what.
            $record->disableLogging();
            $record->markReverted($by);
            $record->enableLogging();

            // The record is open again, so bring its stored figures in line with the
            // cycle (accrue() refreshes only the open record; Claimed ones stay frozen).
            // It recomputes from the member's amount and reimbursements — no delta is
            // added, nothing is created, no other record is touched.
            if ($lockedMember->is_active && $lockedMember->deduction_start_date !== null) {
                $accrualService->refreshCurrentPeriodIfGenerated($lockedMember);
            }

            activity('benefit_period')
                ->performedOn($record)
                ->causedBy($by)
                ->withProperties([
                    'action' => 'Revert Benefit Period Record',
                    'member_id' => $member->id,
                    'member_code' => $member->code,
                    'coverage_year' => $record->coverage_year ?? $record->from_date->year,
                    'record_no' => $record->record_no,
                    'benefit_period' => $record->from_date->toDateString().' to '.$record->to_date->toDateString(),
                    'previous_status' => 'Claimed',
                    'new_status' => 'Unclaimed (Reverted)',
                    'claimed_at' => optional($claimedAt)->toDateTimeString(),
                    'reverted_at' => $record->reverted_at->toDateTimeString(),
                    'reverted_by' => $by->id,
                ])
                ->log(sprintf(
                    '%s of %s reverted: Claimed → Unclaimed. No reimbursement was moved or deleted, and no other record was changed.',
                    $this->label($record),
                    $member->code,
                ));

            return null;
        });

        return redirect()->route('members.show', $member)->with('status', $refusal === null
            ? "{$label} reverted — it is Unclaimed again and accepts reimbursements. Its reimbursement history and every other record are unchanged."
            : "{$label}: {$refusal}");
    }

    /** "Benefit Period record #2 (Apr 2026 – Mar 2027)". */
    private function label(BenefitPeriod $record): string
    {
        return sprintf(
            'Benefit Period record #%d (%s – %s)',
            $record->record_no ?? 1,
            $record->from_date->format('M Y'),
            $record->to_date->format('M Y'),
        );
    }
}
