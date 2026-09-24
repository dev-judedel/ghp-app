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
        $orAmount = (float) $request->validated('or_amount');

        $reimbursement = DB::transaction(function () use ($request, $member, $accrualService, $orDate, $orAmount) {
            $lockedMember = $this->lockMember($member);

            $this->assertWithinBalance($accrualService, $lockedMember, $orDate, $orAmount);

            return $lockedMember->reimbursements()->create($request->validated());
        });

        $this->refreshCurrentPeriod($member, $accrualService);
        $this->linkToBenefitPeriod($member, $reimbursement, $accrualService);

        return redirect()->route('members.show', $member)
            ->with('status', "Reimbursement of ₱".number_format($reimbursement->or_amount, 2)." recorded for {$member->code}.");
    }

    public function update(StoreReimbursementRequest $request, Member $member, Reimbursement $reimbursement, BenefitAccrualService $accrualService): RedirectResponse
    {
        abort_unless($reimbursement->member_id === $member->id, 404);

        if ($reimbursement->is_voided) {
            return redirect()->route('members.show', $member)
                ->with('status', 'This reimbursement is voided — unvoid it first before editing.');
        }

        $orDate = Carbon::parse($request->validated('or_date'));
        $orAmount = (float) $request->validated('or_amount');

        DB::transaction(function () use ($request, $member, $reimbursement, $accrualService, $orDate, $orAmount) {
            $lockedMember = $this->lockMember($member);

            // This reimbursement's OWN current amount is still sitting
            // inside "used" at this point (it hasn't been updated yet) —
            // add it back before checking, but only when it was already
            // counted against the SAME coverage period the new or_date
            // falls into; otherwise it belongs to a different period's
            // balance entirely and shouldn't be credited against this one.
            $creditBack = 0.0;

            if (! $reimbursement->is_voided) {
                [$oldFrom, $oldTo] = $accrualService->coveragePeriod($member->member_type, $reimbursement->or_date);
                [$newFrom, $newTo] = $accrualService->coveragePeriod($member->member_type, $orDate);

                if ($oldFrom->equalTo($newFrom) && $oldTo->equalTo($newTo)) {
                    $creditBack = (float) $reimbursement->or_amount;
                }
            }

            $this->assertWithinBalance($accrualService, $lockedMember, $orDate, $orAmount, $creditBack);

            $reimbursement->update($request->validated());
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
     * GHP Reimbursement Rule: Requested Amount <= Available GHP Amount.
     * ================================================================
     *
     * The FINAL, authoritative check — store()/update() above call this
     * from inside a DB::transaction() with the member row already locked
     * (see lockMember()), so it always evaluates against a fresh,
     * consistent balance no other in-flight request can be mid-write on.
     * If two reimbursements for the same member are submitted at nearly
     * the same instant, the second one's transaction blocks on the lock
     * until the first commits, then re-reads the now-updated balance —
     * so it's impossible for both to be validated against the same
     * "before" balance and jointly drive it negative.
     *
     * $creditBack lets update() add the reimbursement's own current
     * amount back onto the available balance before comparing, since
     * that amount is still counted in "used" until this save replaces it
     * (see update() above for when it applies).
     *
     * Throwing ValidationException here (rather than only in the
     * FormRequest, which runs before the lock is held and so can't be the
     * real guarantee) still redirects back with the error in the normal
     * $errors bag under 'or_amount' — the reimbursement modal in
     * members/show.blade.php already displays that.
     */
    private function assertWithinBalance(BenefitAccrualService $accrualService, Member $member, Carbon $orDate, float $orAmount, float $creditBack = 0.0): void
    {
        $member->load('dependents', 'benefitPeriods');

        $available = $accrualService->availableBalanceFor($member, $orDate)['available'] + $creditBack;

        if ($orAmount > $available) {
            throw ValidationException::withMessages([
                'or_amount' => sprintf(
                    'Insufficient GHP balance. Available GHP Amount: ₱%s. Requested Amount: ₱%s. The reimbursement amount cannot exceed the remaining GHP balance.',
                    number_format($available, 2),
                    number_format($orAmount, 2)
                ),
            ]);
        }
    }

    /**
     * Locks the member row for the duration of the enclosing transaction —
     * see assertWithinBalance() above for why. Must be called from inside
     * DB::transaction(); the lock releases on commit/rollback.
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
     * coverage range (Employees Apr–Mar, Agents Jun–May — see
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
        [$from, $to] = $accrualService->coveragePeriod($member->member_type, $reimbursement->or_date);

        // Excludes voided periods (see BenefitPeriod::void()) so a
        // reimbursement never links to a mistakenly-generated period that
        // was since corrected — if that cycle's period was voided and not
        // yet regenerated, the reimbursement is simply left unlinked, same
        // as any cycle with no benefit period row at all.
        $period = $member->benefitPeriods()
            ->where('is_voided', false)
            ->whereDate('from_date', $from->toDateString())
            ->whereDate('to_date', $to->toDateString())
            ->first();

        if ($period && $reimbursement->benefit_period_id !== $period->id) {
            $reimbursement->update(['benefit_period_id' => $period->id]);
        }
    }
}
