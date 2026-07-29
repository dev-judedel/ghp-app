<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAmountAdjustmentRequest;
use App\Models\Member;
use App\Services\BenefitAccrualService;
use Illuminate\Http\RedirectResponse;

class AmountAdjustmentController extends Controller
{
    public function store(StoreAmountAdjustmentRequest $request, Member $member, BenefitAccrualService $accrualService): RedirectResponse
    {
        $oldAmount = (float) $member->ghp_amount;
        $newAmount = (float) $request->validated('new_amount');

        $member->amountAdjustments()->create([
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'reason' => $request->validated('reason'),
            'request_reference' => $request->validated('request_reference'),
            'requested_at' => $request->validated('requested_at') ?? now()->toDateString(),
            'recorded_by' => $request->user()->name,
        ]);

        // This is what makes the override stick — see BenefitAccrualService::
        // resolveGhpAmount(). Without ghp_amount_is_manual, the next
        // dependent change or "Generate benefit period" click would
        // silently recalculate this back to 3600/4200.
        $member->update([
            'ghp_amount' => $newAmount,
            'ghp_amount_is_manual' => true,
        ]);

        $this->refreshCurrentPeriod($member, $accrualService);

        return redirect()->route('members.show', $member)
            ->with('status', "GHP amount for {$member->code} changed from ₱".number_format($oldAmount, 2)." to ₱".number_format($newAmount, 2).'. This override will stick until reverted to automatic.');
    }

    public function revertToAutomatic(Member $member, BenefitAccrualService $accrualService): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        if (! $member->ghp_amount_is_manual) {
            return redirect()->route('members.show', $member)->with('status', 'This member is already on the automatic GHP amount.');
        }

        $oldAmount = (float) $member->ghp_amount;

        $member->update(['ghp_amount_is_manual' => false]);
        $member->loadMissing('dependents');
        $newAmount = $accrualService->resolveGhpAmount($member);
        $member->update(['ghp_amount' => $newAmount]);

        $member->amountAdjustments()->create([
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'reason' => 'Reverted to automatic (based on current dependents)',
            'request_reference' => null,
            'requested_at' => now()->toDateString(),
            'recorded_by' => auth()->user()->name,
        ]);

        $this->refreshCurrentPeriod($member, $accrualService);

        return redirect()->route('members.show', $member)
            ->with('status', "GHP amount for {$member->code} reverted to automatic (₱".number_format($newAmount, 2).' based on current dependents).');
    }

    private function refreshCurrentPeriod(Member $member, BenefitAccrualService $accrualService): void
    {
        if ($member->is_active && $member->deduction_start_date !== null) {
            $member->load('dependents', 'reimbursements', 'benefitPeriods');
            $accrualService->accrue($member);
        }
    }
}
