<?php

namespace App\Http\Requests;

use App\Models\BenefitPeriod;
use App\Models\ExcessDeduction;
use App\Models\Member;
use App\Models\Reimbursement;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

/**
 * Recording an excess deduction: the ACTUAL amount deducted for one month of
 * a benefit period. The required amount (the period's monthly GHP) and the
 * excess are computed by the server in ExcessDeductionController — the browser
 * never supplies them. Tracking only; see App\Models\ExcessDeduction.
 */
class StoreExcessDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->route('member');
        $period = $this->route('benefitPeriod');

        if ($member instanceof Member && $period instanceof BenefitPeriod) {
            abort_unless($period->member_id === $member->id, 404);
        }

        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'deduction_month' => ['required', 'date'],
            'actual_deduction' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'reimbursement_id' => ['nullable', 'integer'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'deduction_month.required' => 'Choose the month the deduction was made.',
            'deduction_month.date' => 'The deduction month must be a valid date.',
            'actual_deduction.required' => 'Enter the actual amount deducted.',
            'actual_deduction.numeric' => 'The actual deduction must be a valid number.',
            'actual_deduction.min' => 'The actual deduction must be greater than zero.',
            'actual_deduction.max' => 'That amount is too large to record.',
            'actual_deduction.decimal' => 'The actual deduction can have at most 2 decimal places.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['deduction_month', 'actual_deduction', 'reimbursement_id'])) {
                return;
            }

            $member = $this->route('member');
            $period = $this->route('benefitPeriod');

            if (! $member instanceof Member || ! $period instanceof BenefitPeriod) {
                return;
            }

            try {
                $month = Carbon::parse($this->input('deduction_month'))->startOfMonth();
            } catch (Throwable) {
                return;
            }

            // The deduction month must be a month of THIS benefit period.
            if ($month->copy()->endOfMonth()->lessThan($period->from_date) || $month->greaterThan($period->to_date)) {
                $validator->errors()->add(
                    'deduction_month',
                    'The deduction month must fall within this benefit period ('.$period->from_date->format('M d, Y').' – '.$period->to_date->format('M d, Y').').'
                );

                return;
            }

            // No GHP deduction is required before the member's deductions start.
            if ($member->deduction_start_date === null) {
                $validator->errors()->add('deduction_month', "This member has no deduction start date on file, so there is no required GHP deduction to compare against.");

                return;
            }

            if ($month->lessThan($member->deduction_start_date->copy()->startOfMonth())) {
                $validator->errors()->add(
                    'deduction_month',
                    'GHP deductions for this member start '.$member->deduction_start_date->format('M d, Y').', so no deduction is required (or recorded) before then.'
                );

                return;
            }

            // Duplicate guard (the unique index is the last-resort backstop).
            $alreadyRecorded = ExcessDeduction::where('member_id', $member->id)
                ->where('benefit_period_id', $period->id)
                ->whereDate('deduction_month', $month->toDateString())
                ->exists();

            if ($alreadyRecorded) {
                $validator->errors()->add('deduction_month', 'An excess deduction is already recorded for '.$month->format('F Y').' in this benefit period.');

                return;
            }

            // Optional link to one of this period's reimbursement records.
            if ($this->filled('reimbursement_id')) {
                $reimbursement = Reimbursement::find($this->input('reimbursement_id'));

                $belongs = $reimbursement !== null
                    && $reimbursement->member_id === $member->id
                    && ! $reimbursement->is_voided
                    && ($reimbursement->benefit_period_id === $period->id
                        || ($reimbursement->benefit_period_id === null
                            && $reimbursement->or_date->betweenIncluded($period->from_date, $period->to_date)));

                if (! $belongs) {
                    $validator->errors()->add('reimbursement_id', "Choose one of this member's active reimbursement records from this benefit period.");
                } elseif (ExcessDeduction::where('reimbursement_id', $reimbursement->id)->exists()) {
                    $validator->errors()->add('reimbursement_id', 'That reimbursement is already associated with an excess deduction.');
                }
            }
        });
    }
}
