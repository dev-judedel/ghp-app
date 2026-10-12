<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReimbursementRequest;
use App\Http\Requests\VoidReimbursementRequest;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReimbursementController extends Controller
{
    /**
     * A Benefit Period RECORD collects every reimbursement filed until it is Claimed
     * (task.md §2.35). Filing goes into the cycle's open (Unclaimed) record; once that
     * record is Claimed it is locked, and the NEXT filing opens a new record for the
     * same cycle (BenefitAccrualService::recordForFiling()). A reimbursement that
     * belongs to a Claimed record can't be edited, voided or un-voided — revert that
     * record's claim first. These are the guard messages.
     */
    public const LOCKED_MESSAGE = 'This reimbursement belongs to a Benefit Period record that is Claimed and locked. Revert that record\'s claim first if the reimbursement needs to be edited, voided or restored.';

    public const DUPLICATE_MESSAGE = 'That reimbursement was already recorded — the repeated submission was ignored, so nothing was filed twice.';

    public function store(StoreReimbursementRequest $request, Member $member, BenefitAccrualService $accrualService): RedirectResponse
    {
        $orDate = Carbon::parse($request->validated('or_date'));

        $token = $request->validated('submission_token');

        try {
            [$reimbursement, $created] = DB::transaction(function () use ($request, $member, $accrualService, $orDate, $token) {
                $lockedMember = $this->lockMember($member);

                // Duplicate submission (double click, re-sent request, resubmitted
                // page): the form's one-time token already produced a record for
                // this member, so return THAT one and file nothing. Checked under
                // the member lock and BEFORE the balance check — the first filing
                // may have used up the balance, which must not turn a harmless
                // repeat into a confusing "no available balance" error.
                if ($token !== null && $token !== '') {
                    $existing = $lockedMember->reimbursements()->where('submission_token', $token)->first();

                    if ($existing !== null) {
                        return [$existing, false];
                    }
                }

                // Positive Available GHP is required (no amount cap); the same
                // figure is then used to work out the excess GHP for this claim.
                $available = $this->assertHasAvailableBalance($accrualService, $lockedMember, $orDate);

                // Which Benefit Period RECORD receives it: the cycle's open one (any
                // number of reimbursements share it until it is Claimed); a NEW record
                // for the same cycle only when every existing record is Claimed. That
                // new record exists only because this filing needs it, and it carries
                // the cycle's remaining balance — never a fresh annual grant. Done under
                // the member lock (and the records are locked too), in this same
                // transaction, so a concurrent Claim or filing can't slip past it: if the
                // claim wins the lock first this filing opens the next record, if the
                // filing wins first it joins the record before it is locked.
                $record = $accrualService->recordForFiling($lockedMember, $orDate);

                $data = $this->withExcess($request->validated(), $available);

                if ($record !== null) {
                    $data['benefit_period_id'] = $record->id;
                }

                return [$lockedMember->reimbursements()->create($data), true];
            });
        } catch (UniqueConstraintViolationException) {
            // Backstop: the UNIQUE index on submission_token refused a second
            // row. The transaction rolled back, so nothing partial exists.
            return redirect()->route('members.show', $member)->with('status', self::DUPLICATE_MESSAGE);
        }

        if (! $created) {
            return redirect()->route('members.show', $member)->with('status', self::DUPLICATE_MESSAGE);
        }

        $this->refreshCurrentPeriod($member, $accrualService);
        $this->linkToBenefitPeriod($member, $reimbursement, $accrualService);

        $message = "Reimbursement of ₱".number_format($reimbursement->or_amount, 2)." recorded for {$member->code}.";

        $record = $reimbursement->fresh()->benefitPeriod;

        if ($record !== null && (int) $record->record_no > 1) {
            $message .= " Filed into Benefit Period record #{$record->record_no} for this coverage period (the earlier record is Claimed and stays unchanged).";
        }

        if ((float) $reimbursement->excess_amount > 0) {
            $message .= sprintf(
                ' It is above the available GHP of ₱%s, so an excess of ₱%s was recorded automatically (tracking only — GHP usage is unchanged).',
                number_format($reimbursement->available_ghp, 2),
                number_format($reimbursement->excess_amount, 2),
            );
        }

        return redirect()->route('members.show', $member)->with('status', $message);
    }

    public function update(StoreReimbursementRequest $request, Member $member, Reimbursement $reimbursement, BenefitAccrualService $accrualService): RedirectResponse
    {
        abort_unless($reimbursement->member_id === $member->id, 404);

        if ($reimbursement->is_voided) {
            return redirect()->route('members.show', $member)
                ->with('status', 'This reimbursement is voided — unvoid it first before editing.');
        }

        if ($reimbursement->isLocked()) {
            return redirect()->route('members.show', $member)->with('status', self::LOCKED_MESSAGE);
        }

        $orDate = Carbon::parse($request->validated('or_date'));

        $locked = DB::transaction(function () use ($request, $member, $reimbursement, $accrualService, $orDate) {
            $lockedMember = $this->lockMember($member);

            // Re-check the record's state under a lock: a claim may have landed
            // since the page was loaded. A Claimed record is never changed.
            if (Reimbursement::whereKey($reimbursement->id)->lockForUpdate()->firstOrFail()->isLocked()) {
                return true;
            }

            // Editing a claim that is already on file doesn't need a fresh
            // balance: the "positive Available GHP" requirement was met when
            // it was filed, and it must stay correctable (a typo in the amount
            // or OR number) even if this very claim used up the balance. It
            // only counts as a NEW filing — and needs a positive balance —
            // when the change moves it into a different coverage period.
            [$oldFrom, $oldTo] = $accrualService->coveragePeriod($member, $reimbursement->or_date);
            [$newFrom, $newTo] = $accrualService->coveragePeriod($member, $orDate);

            $movesToAnotherPeriod = ! ($oldFrom->equalTo($newFrom) && $oldTo->equalTo($newTo));

            // Which Available GHP is this edited claim measured against?
            //   - Moved into another coverage period: it is a NEW filing there,
            //     so it needs a positive balance and is measured against that
            //     period's Available GHP as it is now.
            //   - Same period, and a figure was recorded when it was filed: keep
            //     that RECORDED Available GHP untouched and only recalculate the
            //     excess from the new amount (excess = amount - recorded
            //     Available GHP, never below 0). The recorded figure is history:
            //     later claims or balance changes must not rewrite it.
            //   - Older claim with nothing recorded: derive it once from the
            //     period's fund minus the OTHER active claims, and record it.
            // A move into another coverage period is a NEW filing there: it goes into
            // that cycle's open record (or opens the next one if all are Claimed).
            // Otherwise the reimbursement stays in the record it is already in.
            $relink = false;
            $newRecordId = null;

            if ($movesToAnotherPeriod) {
                $available = $this->assertHasAvailableBalance($accrualService, $lockedMember, $orDate);
                $newRecord = $accrualService->recordForFiling($lockedMember, $orDate);
                $relink = true;
                $newRecordId = $newRecord?->id;
            } elseif ($reimbursement->available_ghp !== null) {
                $available = (float) $reimbursement->available_ghp;
            } else {
                $available = $this->availableExcludingClaim($accrualService, $lockedMember, $orDate, $reimbursement);
            }

            // The submission token only identifies a NEW filing; it is never changed on an edit.
            $data = $this->withExcess(Arr::except($request->validated(), ['submission_token']), $available, $reimbursement);

            if ($relink) {
                $data['benefit_period_id'] = $newRecordId;
            }

            $reimbursement->update($data);

            return false;
        });

        if ($locked) {
            return redirect()->route('members.show', $member)->with('status', self::LOCKED_MESSAGE);
        }

        $this->refreshCurrentPeriod($member, $accrualService);
        $this->linkToBenefitPeriod($member, $reimbursement, $accrualService);

        return redirect()->route('members.show', $member)
            ->with('status', "Reimbursement updated for {$member->code}.");
    }

    public function void(VoidReimbursementRequest $request, Member $member, Reimbursement $reimbursement, BenefitAccrualService $accrualService): RedirectResponse
    {
        abort_unless($reimbursement->member_id === $member->id, 404);

        if ($reimbursement->is_voided) {
            return redirect()->route('members.show', $member)->with('status', 'That reimbursement is already voided.');
        }

        // A Claimed record is finalized: it can't be voided (revert the claim
        // first). Decided under a row lock so a concurrent claim can't slip past.
        $outcome = DB::transaction(function () use ($request, $reimbursement) {
            $current = Reimbursement::whereKey($reimbursement->id)->lockForUpdate()->firstOrFail();

            if ($current->is_voided) {
                return 'already';
            }

            if ($current->isLocked()) {
                return 'locked';
            }

            $current->void($request->validated('reason'), $request->user());

            return 'voided';
        });

        if ($outcome === 'locked') {
            return redirect()->route('members.show', $member)->with('status', self::LOCKED_MESSAGE);
        }

        if ($outcome === 'already') {
            return redirect()->route('members.show', $member)->with('status', 'That reimbursement is already voided.');
        }

        $reimbursement->refresh();

        $this->refreshCurrentPeriod($member, $accrualService);

        return redirect()->route('members.show', $member)
            ->with('status', "Reimbursement of ₱".number_format($reimbursement->or_amount, 2)." voided for {$member->code}. It no longer counts against the GHP fund, but stays on record.");
    }

    public function unvoid(Member $member, Reimbursement $reimbursement, BenefitAccrualService $accrualService): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_unless($reimbursement->member_id === $member->id, 404);

        if (! $reimbursement->is_voided) {
            return redirect()->route('members.show', $member)->with('status', 'That reimbursement was not voided.');
        }

        // Un-voiding puts it back against the GHP fund, which would change the
        // figures of a Claimed (finalized) record — so it's refused until that
        // record's claim is reverted.
        if ($reimbursement->isClaimed()) {
            return redirect()->route('members.show', $member)->with('status', self::LOCKED_MESSAGE);
        }

        $reimbursement->unvoid();

        $this->refreshCurrentPeriod($member, $accrualService);

        return redirect()->route('members.show', $member)
            ->with('status', "Reimbursement un-voided for {$member->code} — it now counts against the GHP fund again.");
    }

    /**
     * ================================================================
     * GHP Reimbursement Rule: the member must HAVE an Available GHP balance.
     * ================================================================
     *
     * The balance is a PREREQUISITE for filing, not a ceiling on the amount:
     *   - Available GHP > 0  -> the reimbursement can be filed, whatever its
     *     amount (₱1,000 available / ₱5,000 claim is allowed);
     *   - Available GHP <= 0 (none left, or never accrued) -> not allowed.
     * There is no maximum reimbursement amount. The amount only has to be a
     * valid positive number (StoreReimbursementRequest).
     *
     * This changes nothing in the GHP calculation. BenefitAccrualService::
     * calculate() still counts as "used" only what the fund can cover (the
     * full OR amount stays on the reimbursement record), so the GHP amount,
     * monthly GHP, required amount and the accrual rules are untouched.
     *
     * The FINAL, authoritative check — store()/update() call this from inside
     * a DB::transaction() with the member row already locked (see
     * lockMember()), so two near-simultaneous filings can't both be validated
     * against the same "before" balance. The page's JavaScript makes the same
     * check for immediate feedback but is never trusted on its own.
     *
     * Throws a ValidationException under 'or_amount', which the File
     * reimbursement modal in members/show.blade.php already displays.
     *
     * Returns the Available GHP that was checked, so the caller can work out
     * the excess GHP from the very same figure (see withExcess()).
     */
    private function assertHasAvailableBalance(BenefitAccrualService $accrualService, Member $member, Carbon $orDate): float
    {
        $member->load('dependents', 'benefitPeriods');

        $available = $accrualService->availableBalanceFor($member, $orDate)['available'];

        if ($available <= 0) {
            throw ValidationException::withMessages([
                'or_amount' => sprintf(
                    "Can't file this reimbursement: %s has no available GHP balance for the coverage period of the OR date (Available GHP: ₱%s). A positive available balance is required to file a reimbursement — the amount itself is not limited by it.",
                    $member->code,
                    number_format(max($available, 0), 2)
                ),
            ]);
        }

        return (float) $available;
    }

    /**
     * ================================================================
     * Automatic EXCESS GHP.
     * ================================================================
     *
     *     excess GHP = reimbursement amount - Available GHP   (only when > 0)
     *
     * Calculated here on the server from the member's Available GHP for the
     * OR date's coverage period at the time of filing; the browser never
     * supplies it. The claim is always filed and kept at its full OR amount.
     * available_ghp and excess_amount are stored on the reimbursement itself
     * (one column each, so a claim can never have two excess records) purely
     * for tracking and reporting.
     *
     * Nothing here touches GHP usage: filing never changes what counts as used
     * (BenefitAccrualService only reads excess_covered_amount, which is set
     * later by a dependent-driven fund increase — see applyFundIncreaseToExcess()
     * — and even then keeps it out of "used"). The period is refreshed by the
     * same calculation as before (used = what the fund can cover, Available
     * floors at 0).
     */
    private function withExcess(array $data, float $available, ?Reimbursement $existing = null): array
    {
        $available = max(round($available, 2), 0.0);
        $amount = round((float) $data['or_amount'], 2);
        $original = max(round($amount - $available, 2), 0.0);

        // On an EDIT, whatever a dependent-driven increase has already covered
        // is kept (never re-applied, never lost) — only capped at the new
        // original excess if the amount was lowered. On a new claim: 0.
        $covered = $existing ? min(round((float) $existing->excess_covered_amount, 2), $original) : 0.0;

        $data['available_ghp'] = $available;
        // excess_amount is the REMAINING (still outstanding) excess: original - covered.
        $data['original_excess_amount'] = $original;
        $data['excess_covered_amount'] = $covered;
        $data['excess_amount'] = round($original - $covered, 2);

        return $data;
    }

    /**
     * Available GHP for the period containing $orDate as seen by ONE existing
     * claim: the period's fund (accrued + carry-forward) minus every OTHER
     * active claim in that period. Only used for OLDER claims that were filed
     * before Available GHP was recorded (see update()); claims with a
     * recorded figure keep it.
     */
    private function availableExcludingClaim(BenefitAccrualService $accrualService, Member $member, Carbon $orDate, Reimbursement $claim): float
    {
        $member->load('dependents', 'benefitPeriods');

        $balance = $accrualService->availableBalanceFor($member, $orDate);
        $fund = (float) $balance['accrued'] + (float) $balance['carry_forward'];

        $others = (float) $member->reimbursements()
            ->notVoided()
            ->whereKeyNot($claim->getKey())
            ->whereBetween('or_date', [$balance['from'], $balance['to']])
            ->sum('or_amount');

        return max(round($fund - $others, 2), 0.0);
    }

    /**
     * Locks the member row for the duration of the enclosing transaction —
     * see assertHasAvailableBalance() above for why. Must be called from
     * inside DB::transaction(); the lock releases on commit/rollback.
     */
    private function lockMember(Member $member): Member
    {
        return Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Refresh the current benefit period so the balance shown on the member
     * page reflects any reimbursement change immediately, same as the
     * "Generate benefit period" action.
     */
    private function refreshCurrentPeriod(Member $member, BenefitAccrualService $accrualService): void
    {
        if ($member->is_active && $member->deduction_start_date !== null) {
            $member->load('dependents', 'reimbursements', 'benefitPeriods');
            $accrualService->accrue($member);
        }
    }

    /**
     * Keeps reimbursements.benefit_period_id pointing at the Benefit Period RECORD
     * the reimbursement belongs to (task.md §2.35). A reimbursement is linked to its
     * record when it is filed (BenefitAccrualService::recordForFiling()) and STAYS
     * in that record — editing it, or filing another one, never moves it. With
     * several records per coverage period, "the period that contains the OR date"
     * no longer identifies one row, so this never re-matches by dates once linked.
     *
     * What remains is the legacy case: a reimbursement with no record yet (filed
     * before the link existed, or backdated into a cycle that had none) is linked to
     * the cycle's OPEN record if one exists. It is never linked into a Claimed
     * record (that would change a locked record) and no record is created here.
     */
    private function linkToBenefitPeriod(Member $member, Reimbursement $reimbursement, BenefitAccrualService $accrualService): void
    {
        [$from, $to] = $accrualService->coveragePeriod($member, $reimbursement->or_date);

        $current = $reimbursement->fresh()->benefitPeriod;

        if ($current !== null && $current->from_date->isSameDay($from) && $current->to_date->isSameDay($to)) {
            return;
        }

        $open = $member->benefitPeriods()
            ->whereDate('from_date', $from->toDateString())
            ->whereDate('to_date', $to->toDateString())
            ->whereNull('claimed_at')
            ->orderByDesc('record_no')
            ->first();

        if ($open && $reimbursement->benefit_period_id !== $open->id) {
            $reimbursement->update(['benefit_period_id' => $open->id]);
        }
    }
}
