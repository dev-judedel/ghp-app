<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAmountAdjustmentRequest;
use App\Models\BenefitAmountAdjustment;
use App\Models\Member;
use App\Services\AmountAdjustmentService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class AmountAdjustmentController extends Controller
{
    public function store(StoreAmountAdjustmentRequest $request, Member $member, AmountAdjustmentService $adjustments): RedirectResponse
    {
        $oldAmount = (float) $member->ghp_amount;
        $newAmount = (float) $request->validated('new_amount');

        try {
            $adjustment = $adjustments->apply(
                $member,
                $newAmount,
                $request->validated('reason'),
                $request->validated('request_reference'),
                $request->validated('requested_at'),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            // E.g. the member was deactivated after the page loaded. Nothing was changed.
            return redirect()->route('members.show', $member)->with('status', $e->getMessage());
        }

        $status = "GHP amount for {$member->code} changed from ₱".number_format($oldAmount, 2).' to ₱'.number_format($newAmount, 2).'. This override will stick until reverted to automatic.';

        // Mirrors the same "covered excess first, rest to Available GHP"
        // message the dependent-eligibility flow doesn't need to show
        // (that one is silent/automatic) — here the admin just typed in a
        // number, so the effect on an outstanding claim's excess is called
        // out explicitly rather than left for them to notice on the page.
        if ((float) $adjustment->excess_covered_amount > 0) {
            $status .= ' ₱'.number_format($adjustment->excess_covered_amount, 2).' of the increase covered outstanding excess GHP';
            $status .= (float) $adjustment->available_increase_amount > 0
                ? '; the remaining ₱'.number_format($adjustment->available_increase_amount, 2).' was added to Available GHP.'
                : '.';
        }

        return redirect()->route('members.show', $member)->with('status', $status);
    }

    /**
     * Reverts ONE adjustment (the most recent active one) — unlike
     * revertToAutomatic() below, which undoes the whole chain. The service
     * decides whether it is allowed and returns a reason string if not.
     */
    public function revert(Member $member, BenefitAmountAdjustment $adjustment, AmountAdjustmentService $adjustments): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        // An adjustment id from another member's page must never be actionable here.
        abort_unless($adjustment->member_id === $member->id, 404);

        try {
            $result = $adjustments->revertAdjustment($member, $adjustment, auth()->user());
        } catch (RuntimeException $e) {
            return redirect()->route('members.show', $member)->with('status', $e->getMessage());
        }

        if (is_string($result)) {
            return redirect()->route('members.show', $member)->with('status', $result);
        }

        $status = "GHP adjustment reverted for {$member->code}: the amount is now ₱".number_format($result->new_amount, 2).'.';

        if ((float) $result->excess_covered_amount > 0) {
            $status .= ' ₱'.number_format($result->excess_covered_amount, 2).' of excess GHP that this adjustment had covered was restored.';
        }

        return redirect()->route('members.show', $member)->with('status', $status);
    }

    public function revertToAutomatic(Member $member, AmountAdjustmentService $adjustments): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        try {
            $result = $adjustments->revertToAutomatic($member, auth()->user());
        } catch (RuntimeException $e) {
            return redirect()->route('members.show', $member)->with('status', $e->getMessage());
        }

        if (is_string($result)) {
            return redirect()->route('members.show', $member)->with('status', $result);
        }

        $status = "GHP amount for {$member->code} reverted to automatic (₱".number_format($result->new_amount, 2).' based on current dependents).';

        if ((float) $result->excess_covered_amount > 0) {
            $status .= ' ₱'.number_format($result->excess_covered_amount, 2).' of excess GHP previously covered by the adjustment(s) was restored.';
        }

        return redirect()->route('members.show', $member)->with('status', $status);
    }
}
