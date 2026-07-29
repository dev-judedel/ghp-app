<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReimbursementRequest;
use App\Http\Requests\VoidReimbursementRequest;
use App\Models\Member;
use App\Models\Reimbursement;
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

        $this->refreshCurrentPeriod($member, $accrualService);

        return redirect()->route('members.show', $member)->with('status', $message);
    }

    public function update(StoreReimbursementRequest $request, Member $member, Reimbursement $reimbursement, BenefitAccrualService $accrualService): RedirectResponse
    {
        abort_unless($reimbursement->member_id === $member->id, 404);

        if ($reimbursement->is_voided) {
            return redirect()->route('members.show', $member)
                ->with('status', 'This reimbursement is voided — unvoid it first before editing.');
        }

        $reimbursement->update($request->validated());

        $this->refreshCurrentPeriod($member, $accrualService);

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
}
