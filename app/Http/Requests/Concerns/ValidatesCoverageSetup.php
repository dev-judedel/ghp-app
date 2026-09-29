<?php

namespace App\Http\Requests\Concerns;

use App\Models\Member;
use App\Services\BenefitAccrualService;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Throwable;

/**
 * Validation for the editable GHP Benefit Setup fields shared by the Add
 * Member and Edit Member forms: Coverage year, Apply Date, End Date and the
 * deduction Start Date. (The GHP amount is validated by the request itself,
 * since only Add Member takes it.)
 *
 * The three coverage fields are optional at the request level so a member
 * with no configured cycle keeps the standard member-type cycle exactly as
 * before (imports, older records, older clients). The forms always send
 * them, pre-filled with defaults.
 */
trait ValidatesCoverageSetup
{
    protected function coverageRules(): array
    {
        return [
            'coverage_year' => ['nullable', 'integer', 'between:2000,2100', 'required_with:coverage_end_date'],
            // "later than the Apply Date" is the core date rule; the checks in
            // validateCoverageSetup() build on it once every date is known valid.
            'coverage_end_date' => ['nullable', 'date', 'required_with:coverage_year', 'after:apply_date'],
            'deduction_start_date' => ['nullable', 'date'],
        ];
    }

    protected function coverageMessages(): array
    {
        return [
            'coverage_year.integer' => 'Coverage year must be a valid year, e.g. 2026.',
            'coverage_year.between' => 'Coverage year must be between 2000 and 2100.',
            'coverage_year.required_with' => 'Coverage year is required when an End Date is set.',
            'coverage_end_date.date' => 'End Date must be a valid date.',
            'coverage_end_date.required_with' => 'End Date is required when a Coverage year is set.',
            'coverage_end_date.after' => 'End Date must be later than the Apply Date.',
            'deduction_start_date.date' => 'Start Date (deduction) must be a valid date.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Cross-field checks only make sense once every field they read
            // is itself a valid value; the simple errors above show first.
            if ($validator->errors()->hasAny(['apply_date', 'coverage_year', 'coverage_end_date', 'deduction_start_date'])) {
                return;
            }

            $this->validateCoverageSetup($validator);
        });
    }

    private function validateCoverageSetup(Validator $validator): void
    {
        $service = app(BenefitAccrualService::class);
        $member = $this->route('member');
        $member = $member instanceof Member ? $member : null;   // null = Add Member

        $apply = $this->coverageDate($this->input('apply_date'));
        $end = $this->coverageDate($this->input('coverage_end_date'));
        $ded = $this->coverageDate($this->input('deduction_start_date'));

        // A configured cycle can be changed but not blanked back to "none":
        // that would silently swap the member onto a different calendar.
        if ($member?->coverage_end_date !== null && $end === null) {
            $validator->errors()->add('coverage_end_date', "End Date can't be cleared once a coverage period is set \u{2014} change it to the date you need instead.");

            return;
        }

        if ($end !== null) {
            $anchor = $service->coverageAnchor($end);
            $window = $anchor->format('M d, Y').' – '.$end->format('M d, Y');

            if ((int) $this->input('coverage_year') !== $anchor->year) {
                $validator->errors()->add(
                    'coverage_year',
                    "Coverage year must be {$anchor->year} for a cycle that ends on {$end->format('M d, Y')} (it begins {$anchor->format('M d, Y')})."
                );
            }

            if ($apply !== null && ($apply->lessThan($anchor) || $apply->greaterThan($end))) {
                $validator->errors()->add('apply_date', "Apply Date must fall within the coverage period ({$window}).");
            }

            if ($member !== null && ($conflict = $service->coverageChangeConflict($member, $end)) !== null) {
                $validator->errors()->add('coverage_end_date', $conflict);
            }
        }

        // The deduction Start Date is checked whenever it is being set: always
        // on Add Member, and on Edit only if it (or the dates it depends on)
        // actually changed — so saving an unrelated field of an older member
        // never trips over a stored value that predates these rules.
        $deductionBeingSet = $ded !== null && ($member === null || $this->coverageDatesChanged($member, $apply, $end, $ded));

        if ($deductionBeingSet && $apply !== null) {
            if ($ded->lessThan($apply)) {
                $validator->errors()->add('deduction_start_date', "Start Date must be on or after the Apply Date ({$apply->format('M d, Y')}).");
            } elseif ($end !== null && $ded->greaterThan($end->copy()->addDay())) {
                $validator->errors()->add(
                    'deduction_start_date',
                    "Start Date can't be later than the day after the End Date ({$end->copy()->addDay()->format('M d, Y')})."
                );
            }
        }
    }

    private function coverageDatesChanged(Member $member, ?Carbon $apply, ?Carbon $end, ?Carbon $ded): bool
    {
        $same = fn (?Carbon $submitted, $stored): bool => $submitted === null
            ? $stored === null
            : ($stored !== null && $submitted->isSameDay($stored));

        return ! ($same($apply, $member->apply_date)
            && $same($end, $member->coverage_end_date)
            && $same($ded, $member->deduction_start_date));
    }

    private function coverageDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
