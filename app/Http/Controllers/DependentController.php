<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDependentRequest;
use App\Models\Dependent;
use App\Models\Member;
use App\Services\BenefitAccrualService;
use App\Services\DependentEligibilityService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class DependentController extends Controller
{
    /**
     * ================================================================
     * GHP Dependent Eligibility Rule
     * ================================================================
     * Adding a dependent never raises the member's GHP amount right away.
     * date_added is recorded as today, and eligibility_date is set to the
     * START OF THE NEXT coverage cycle (see BenefitAccrualService::
     * resolveDependentEligibilityDate()) — the dependent is saved and
     * shown immediately (Dependents card), but Dependent::isGhpEligibleAsOf()
     * won't return true for it until $asOf reaches that date. syncGhpAmount()
     * below still recomputes members.ghp_amount right after saving, exactly
     * as before — it just now correctly stays at the base rate until the
     * next cycle, instead of the previous behavior of bumping immediately.
     */
    public function store(StoreDependentRequest $request, Member $member, BenefitAccrualService $accrualService, DependentEligibilityService $eligibility): RedirectResponse
    {
        // Same behavior as before — creation just lives in the shared
        // service now so the Edit Member spouse flow uses the identical rule.
        $eligibility->addDependent($member, $request->validated());

        // Pending dependent => amount stays at the base rate, nothing is
        // raised early; this only changes anything once one is eligible.
        $eligibility->recalculateBenefit($member);

        return redirect()->route('members.show', $member)
            ->with('status', "Dependent added for {$member->code}. Eligible for the higher GHP amount starting the next coverage cycle.");
    }

    /**
     * ================================================================
     * Immediate Eligibility (administrator-controlled exception)
     * ================================================================
     * Lets an admin bypass the normal "wait for the next Benefit Period"
     * rule for ONE dependent. Everything is re-validated here on the
     * backend (admin, dependent-belongs-to-member, still pending) — the
     * hidden/shown button in the view is a convenience, never the gate.
     *
     * Does not touch BenefitPeriod rows: no generation, no reset, no
     * change to Coverage Year History, and the Generate button's
     * one-period-per-cycle rule is unaffected.
     */
    public function immediateEligibility(Member $member, Dependent $dependent, DependentEligibilityService $eligibility): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_unless($dependent->member_id === $member->id, 404);

        try {
            $eligibility->grantImmediate($member, $dependent, auth()->user());
        } catch (RuntimeException $e) {
            return redirect()->route('members.show', $member)->with('status', $e->getMessage());
        }

        return redirect()->route('members.show', $member)
            ->with('status', "{$dependent->name} is now immediately eligible for {$member->code}. The GHP amount and available balance were recalculated by the standard calculation (GHP amount ÷ 12 × months rendered); no benefit period was generated, reopened or voided.");
    }

    public function update(StoreDependentRequest $request, Member $member, Dependent $dependent, DependentEligibilityService $eligibility): RedirectResponse
    {
        abort_unless($dependent->member_id === $member->id, 404);

        $dependent->update($request->validated());

        $eligibility->recalculateBenefit($member);

        return redirect()->route('members.show', $member)
            ->with('status', "Dependent updated for {$member->code}.");
    }

    public function destroy(Member $member, Dependent $dependent, DependentEligibilityService $eligibility): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_unless($dependent->member_id === $member->id, 404);

        $dependent->delete();

        $eligibility->recalculateBenefit($member);

        return redirect()->route('members.show', $member)
            ->with('status', "Dependent removed for {$member->code}.");
    }

    // Dependent changes all go through DependentEligibilityService::
    // recalculateBenefit(): it keeps members.ghp_amount in sync (a manual
    // override is returned unchanged by the engine, so that stays a no-op)
    // and refreshes the current, already-generated benefit period only when
    // eligibility actually changed its GHP amount.
}
