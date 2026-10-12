<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Reimbursement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Claim | Revert on ONE reimbursement record (task.md §2.32).
 *
 * A coverage year can hold many records — one per reimbursement filed — and each
 * has its own lifecycle:
 *
 *   Filed --Claim--> Claimed (LOCKED) --Revert--> Reverted (unlocked)
 *                                                    '--Claim--> Claimed ...
 *
 * Claimed means finalized: the record can't be edited or voided, and no later GHP
 * increase (Adjust GHP amount, a dependent becoming eligible) is ever applied to
 * ITS excess — BenefitAccrualService::applyFundIncreaseToExcess() skips it.
 * Reverting unlocks it again; nothing is covered retroactively just because it
 * was reverted, only future increases may reach it.
 *
 * Both actions touch ONLY this record's claim columns. They never read or change
 * an amount, the benefit period (the GHP fund), excess figures, dependents or any
 * other record, so reverting Record #1 cannot affect Record #2 or #3. Nothing is
 * deleted: the record stays in the history, with who reverted it and when
 * (reverted_at / reverted_by plus an explicit activity-log entry).
 *
 * The state is re-checked on the server under a row lock inside a transaction, so
 * a double click, a stale page or a second administrator can never double-claim or
 * double-revert. The page's buttons are only a convenience. Routes are admin-only
 * and behind 'member.active', so a deactivated member's records stay read-only.
 */
class ReimbursementClaimController extends Controller
{
    public const NOTHING_TO_REVERT = 'This reimbursement record has not been claimed, so there is nothing to revert.';

    public const ALREADY_REVERTED = 'No further revert is available for this reimbursement record.';

    public const VOIDED = 'A voided reimbursement can\'t be claimed or reverted.';

    public function claim(Request $request, Member $member, Reimbursement $reimbursement): RedirectResponse
    {
        abort_unless($reimbursement->member_id === $member->id, 404);

        $outcome = DB::transaction(function () use ($request, $member, $reimbursement) {
            $record = Reimbursement::whereKey($reimbursement->id)->lockForUpdate()->firstOrFail();

            if ($record->is_voided) {
                return 'voided';
            }

            if ($record->isClaimed()) {
                return 'already';
            }

            $record->markClaimed($request->user());

            activity('reimbursement')
                ->performedOn($record)
                ->causedBy($request->user())
                ->withProperties([
                    'action' => 'Claim Reimbursement Record',
                    'member_id' => $member->id,
                    'member_code' => $member->code,
                    'record' => $this->recordLabel($record),
                    'new_status' => 'Claimed',
                ])
                ->log(sprintf('%s of %s claimed — now locked: no later GHP increase will cover its excess.', $this->recordLabel($record), $member->code));

            return 'ok';
        });

        $label = $this->recordLabel($reimbursement);

        return redirect()->route('members.show', $member)->with('status', match ($outcome) {
            'ok' => "{$label} marked as Claimed. It is now locked.",
            'already' => "{$label} is already Claimed — nothing was changed.",
            default => self::VOIDED,
        });
    }

    public function revert(Request $request, Member $member, Reimbursement $reimbursement): RedirectResponse
    {
        abort_unless($reimbursement->member_id === $member->id, 404);

        $outcome = DB::transaction(function () use ($request, $member, $reimbursement) {
            $record = Reimbursement::whereKey($reimbursement->id)->lockForUpdate()->firstOrFail();

            if ($record->is_voided) {
                return 'voided';
            }

            if (! $record->isClaimed()) {
                return $record->isReverted() ? 'already_reverted' : 'nothing';
            }

            $claimedAt = $record->claimed_at;

            // Write ONE explicit entry instead of the model's bare dirty-field lines.
            $record->disableLogging();
            $record->markReverted($request->user());
            $record->enableLogging();

            activity('reimbursement')
                ->performedOn($record)
                ->causedBy($request->user())
                ->withProperties([
                    'action' => 'Revert Reimbursement Record',
                    'member_id' => $member->id,
                    'member_code' => $member->code,
                    'record' => $this->recordLabel($record),
                    'previous_status' => 'Claimed',
                    'new_status' => 'Reverted',
                    'claimed_at' => optional($claimedAt)->toDateTimeString(),
                    'reverted_at' => $record->reverted_at->toDateTimeString(),
                    'reverted_by' => $request->user()->id,
                ])
                ->log(sprintf('%s of %s reverted: Claimed → Reverted (unlocked). No amount, GHP balance or other record was changed.', $this->recordLabel($record), $member->code));

            return 'ok';
        });

        $label = $this->recordLabel($reimbursement);

        return redirect()->route('members.show', $member)->with('status', match ($outcome) {
            'ok' => "{$label} reverted — it is unlocked again. No amount, GHP balance or other record was changed.",
            'already_reverted' => "{$label}: ".self::ALREADY_REVERTED,
            'nothing' => "{$label}: ".self::NOTHING_TO_REVERT,
            default => self::VOIDED,
        });
    }

    /** "Record #2 (2026–2027)" — same numbering the Coverage Year History shows. */
    private function recordLabel(Reimbursement $record): string
    {
        $year = $record->benefitPeriod?->coverage_year ?? $record->or_date->year;

        return sprintf('Record #%d (coverage year %s)', $record->recordNumber(), $year);
    }
}
