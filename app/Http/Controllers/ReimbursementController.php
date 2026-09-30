<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReimbursementRequest;
use App\Http\Requests\VoidReimbursementRequest;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReimbursementController extends Controller
{
    public function store(StoreReimbursementRequest $request, Member $member, BenefitAccrualService $accrualService): RedirectResponse
    {
        $orDate = Carbon::parse($request->validated('or_date'));

        $reimbursement = DB::transaction(function () use ($request, $member, $accrualService, $orDate) {
            $lockedMember = $this->lockMember($member);

            // Positive Available GHP is required (no amount cap); the same
            // figure is then used to work out the excess GHP for this claim.
            $available = $this->assertHasAvailableBalance($accrualService, $lockedMember, $orDate);

            return $lockedMember->reimbursements()->create(
                $this->withExcess($request->validated(), $available)
            );
        });

        $this->refreshCurrentPeriod($member, $accrualService);
        $this->linkToBenefitPeriod($member, $reimbursement, $accrualService);

        $message = "Reimbursement of ₱".number_format($reimbursement->or_amount, 2)." recorded for {$member->code}.";

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

        $orDate = Carbon::parse($request->validated('or_date'));

        DB::transaction(function () use ($request, $member, $reimbursement, $accrualService, $orDate) {
            $lockedMember = $this->lockMember($member);

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
            if ($movesToAnotherPeriod) {
                $available = $this->assertHasAvailableBalance($accrualService, $lockedMember, $orDate);
            } elseif ($reimbursement->available_ghp !== null) {
                $available = (float) $reimbursement->available_ghp;
            } else {
                $available = $this->availableExcludingClaim($accrualService, $lockedMember, $orDate, $reimbursement);
            }

            $reimbursement->update($this->withExcess($request->validated(), $available, $reimbursement));
        });

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

        $reimbursement->void($request->validated('reason'), $request->user());

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
     * Sets reimbursements.benefit_period_id to the BenefitPeriod row whose
     * coverage range (the member's configured cycle, or the standard
     * Employees Apr–Mar / Agents Jun–May one — see
     * BenefitAccrualService::coveragePeriod()) contains this
     * reimbursement's OR date. This is the reliable ID link the Coverage
     * Year History drill-down (BenefitPeriodController::reimbursements())
     * filters on, instead of re-matching dates on every request.
     *
     * Only links to a period that already exists — refreshCurrentPeriod()
     * above just created/updated the CURRENT one, so filing or editing a
     * reimbursement for the current coverage year always links
     * successfully. A reimbursement backdated into a past coverage year
     * that has no BenefitPeriod row yet (rare — periods are normally
     * generated automatically as each year rolls in) is left unlinked
     * rather than fabricating a historical period as a side effect of
     * filing a receipt; it still shows up everywhere it already did, just
     * not from the Coverage Year History drill-down until that period
     * exists.
     */
    private function linkToBenefitPeriod(Member $member, Reimbursement $reimbursement, BenefitAccrualService $accrualService): void
    {
        [$from, $to] = $accrualService->coveragePeriod($member, $reimbursement->or_date);

        $period = $member->benefitPeriods()
            ->whereDate('from_date', $from->toDateString())
            ->whereDate('to_date', $to->toDateString())
            ->first();

        if ($period && $reimbursement->benefit_period_id !== $period->id) {
            $reimbursement->update(['benefit_period_id' => $period->id]);
        }
    }
}
