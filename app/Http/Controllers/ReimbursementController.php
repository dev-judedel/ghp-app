<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReimbursementRequest;
use App\Models\Member;
use App\Services\BenefitAccrualService;
use Illuminate\Http\RedirectResponse;

class ReimbursementController extends Controller
{
    public function store(StoreReimbursementRequest $request, Member $member, BenefitAccrualService $accrualService): RedirectResponse
    {
        $member->loadMissing('dependents', 'reimbursements', 'benefitPeriods');
        $balanceBefore = $accrualService->calculate($member);

        $reimbursement = $member->reimbursements()->create($request->validated());

        $message = "Reimbursement of ₱".number_format($reimbursement->or_amount, 2)." recorded for {$member->code}.";

        if ((float) $reimbursement->or_amount > $balanceBefore['available']) {
            $message .= sprintf(
                ' Note: this exceeds the available balance (₱%s) — the full amount is recorded on the receipt, but only ₱%s counts against the GHP fund; the remaining ₱%s is not covered.',
                number_format($balanceBefore['available'], 2),
                number_format($balanceBefore['available'], 2),
                number_format($reimbursement->or_amount - $balanceBefore['available'], 2)
            );
        }

        // Refresh the current benefit period so the balance shown on the
        // member page reflects this claim immediately, same as the
        // "Generate benefit period" action.
        if ($member->is_active && $member->deduction_start_date !== null) {
            $member->load('benefitPeriods');
            $accrualService->accrue($member);
        }

        return redirect()->route('members.show', $member)->with('status', $message);
    }
}
