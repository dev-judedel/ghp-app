<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExcessDeductionRequest;
use App\Models\BenefitPeriod;
use App\Models\ExcessDeduction;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;

/**
 * Records and removes EXCESS DEDUCTIONS for a member's benefit period.
 *
 * An excess deduction is the part of an actual deduction that is more than
 * the required monthly GHP for the period:
 *
 *     required (period ghp_amount / 12)   300
 *     actual deduction                    500
 *     excess deduction                    200
 *
 * It is a tracking/reporting record ONLY. This controller never touches
 * benefit_periods, reimbursements or members, and BenefitAccrualService never
 * reads the excess_deductions table — so recording (or removing) one cannot
 * change GHP usage, the available balance, the GHP amount, the monthly GHP or
 * the required amount, and it is not a reimbursement or a GHP contribution.
 */
class ExcessDeductionController extends Controller
{
    public function store(StoreExcessDeductionRequest $request, Member $member, BenefitPeriod $benefitPeriod): RedirectResponse
    {
        $back = redirect()->route('members.benefit-periods.reimbursements', [$member, $benefitPeriod]);

        $month = Carbon::parse($request->validated('deduction_month'))->startOfMonth();

        // Computed here, never taken from the browser. The required amount is
        // a snapshot of the period's monthly GHP, so it stays correct in the
        // record even if the period is later recalculated.
        $required = round((float) $benefitPeriod->ghp_amount / 12, 2);
        $actual = round((float) $request->validated('actual_deduction'), 2);
        $excess = round($actual - $required, 2);

        // Only recorded when the actual deduction EXCEEDS what is required.
        if ($excess <= 0) {
            return $back->with('status', sprintf(
                'No excess deduction recorded: the actual deduction (₱%s) does not exceed the required GHP deduction (₱%s) for %s.',
                number_format($actual, 2),
                number_format($required, 2),
                $month->format('F Y'),
            ));
        }

        try {
            $record = ExcessDeduction::create([
                'member_id' => $member->id,
                'benefit_period_id' => $benefitPeriod->id,
                'reimbursement_id' => $request->validated('reimbursement_id') ?: null,
                'deduction_month' => $month->toDateString(),
                'required_amount' => $required,
                'actual_deduction' => $actual,
                'excess_amount' => $excess,
                'remarks' => $request->validated('remarks'),
                'recorded_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two near-simultaneous submits: the unique index let only one through.
            return $back->with('status', 'An excess deduction is already recorded for '.$month->format('F Y').' in this benefit period.');
        }

        $this->log($request->user(), $member, $benefitPeriod, $record, sprintf(
            'Excess deduction recorded for %s: required ₱%s, actual deduction ₱%s, excess ₱%s (tracking only — GHP usage unchanged)',
            $month->format('F Y'),
            number_format($required, 2),
            number_format($actual, 2),
            number_format($excess, 2),
        ));

        return $back->with('status', sprintf(
            'Excess deduction of ₱%s recorded for %s (required ₱%s, actual ₱%s). It is tracked separately and does not change GHP usage or the available balance.',
            number_format($excess, 2),
            $month->format('F Y'),
            number_format($required, 2),
            number_format($actual, 2),
        ));
    }

    public function destroy(Member $member, BenefitPeriod $benefitPeriod, ExcessDeduction $excessDeduction): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_unless($benefitPeriod->member_id === $member->id, 404);
        abort_unless($excessDeduction->member_id === $member->id && $excessDeduction->benefit_period_id === $benefitPeriod->id, 404);

        $description = sprintf(
            'Excess deduction removed for %s: was ₱%s (actual ₱%s vs required ₱%s)',
            $excessDeduction->deduction_month->format('F Y'),
            number_format($excessDeduction->excess_amount, 2),
            number_format($excessDeduction->actual_deduction, 2),
            number_format($excessDeduction->required_amount, 2),
        );

        // Removing it is safe for the GHP figures for the same reason adding it
        // is: they never depended on it. The activity log keeps the record of
        // what it was, who removed it and when.
        $this->log(auth()->user(), $member, $benefitPeriod, $excessDeduction, $description);
        $excessDeduction->delete();

        return redirect()->route('members.benefit-periods.reimbursements', [$member, $benefitPeriod])
            ->with('status', 'Excess deduction removed. GHP usage and the available balance were not affected.');
    }

    private function log($user, Member $member, BenefitPeriod $benefitPeriod, ExcessDeduction $record, string $description): void
    {
        activity('excess_deduction')
            ->performedOn($member)
            ->causedBy($user)
            ->withProperties([
                'benefit_period' => $benefitPeriod->from_date->toDateString().' to '.$benefitPeriod->to_date->toDateString(),
                'deduction_month' => $record->deduction_month->toDateString(),
                'required_amount' => (float) $record->required_amount,
                'actual_deduction' => (float) $record->actual_deduction,
                'excess_amount' => (float) $record->excess_amount,
                'reimbursement_id' => $record->reimbursement_id,
            ])
            ->log($description);
    }
}
