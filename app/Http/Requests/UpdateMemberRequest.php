<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasEmailRule;
use App\Models\Member;
use App\Services\DependentEligibilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    use HasEmailRule;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $member = $this->route('member');

        // ---- Spouse dependent (Edit Member modal) ----
        // The spouse fields only matter when Civil Status is Married and the
        // member has no spouse dependent yet (duplicates are never created —
        // see MemberController::update()). They are REQUIRED only when this
        // edit is what changes the member to Married; a member who was
        // already Married (e.g. legacy data with no spouse on file) isn't
        // forced to add one just to fix an unrelated field, but if they do
        // start filling the section in, name and birthdate must go together.
        $spouseApplicable = $this->spouseSectionApplicable();
        $spouseRequired = $spouseApplicable && $this->isChangingToMarried();

        $spouseNameRules = [Rule::requiredIf($spouseRequired), 'nullable', 'string', 'max:255'];
        $spouseBirthdateRules = [Rule::requiredIf($spouseRequired), 'nullable', 'date', 'before_or_equal:today'];

        if ($spouseApplicable && ! $spouseRequired) {
            $spouseNameRules[] = 'required_with:spouse_birthdate';
            $spouseBirthdateRules[] = 'required_with:spouse_name';
        }

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('members', 'code')->ignore($member?->id)],
            // Nullable (not required) here: legacy members imported before
            // this field existed may not have one yet, and editing an
            // unrelated field (e.g. department) shouldn't force adding an
            // email on the spot. If one is provided, it's fully validated.
            'email' => ['nullable', 'max:255', $this->emailRule(), Rule::unique('members', 'email')->ignore($member?->id)],
            'member_type' => ['required', 'in:0,1'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'birthdate' => ['nullable', 'date'],
            'civil_status' => ['nullable', 'in:0,1'],
            'apply_date' => ['required', 'date'],
            'start_date' => ['required', 'date'],
            // See StoreMemberRequest — deduction_start_date is always
            // recomputed from start_date, never accepted directly.
            'division_id' => ['nullable', 'exists:divisions,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'old_code' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string'],

            'spouse_name' => $spouseNameRules,
            'spouse_birthdate' => $spouseBirthdateRules,
            // Default (and anything unrecognized) is the NORMAL Benefit
            // Period rule — Married never implies Immediate on its own.
            'spouse_eligibility' => ['nullable', 'in:normal,immediate'],
        ];
    }

    /**
     * Civil status Married is being submitted AND this member doesn't
     * already have a spouse dependent on file.
     */
    public function spouseSectionApplicable(): bool
    {
        return $this->submittedMarried() && ! $this->memberAlreadyHasSpouse();
    }

    /**
     * This request is what moves the member TO Married (as opposed to
     * saving an already-Married member).
     */
    public function isChangingToMarried(): bool
    {
        $member = $this->route('member');

        return $this->submittedMarried()
            && $member?->civil_status !== Member::CIVIL_STATUS_MARRIED;
    }

    public function memberAlreadyHasSpouse(): bool
    {
        $member = $this->route('member');

        return $member !== null && app(DependentEligibilityService::class)->hasSpouse($member);
    }

    private function submittedMarried(): bool
    {
        return $this->filled('civil_status')
            && (int) $this->input('civil_status') === Member::CIVIL_STATUS_MARRIED;
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A member with this code already exists.',
            'spouse_name.required' => 'Spouse name is required.',
            'spouse_name.required_with' => 'Spouse name is required.',
            'spouse_birthdate.required' => 'Spouse birthday is required.',
            'spouse_birthdate.required_with' => 'Spouse birthday is required.',
            'spouse_birthdate.before_or_equal' => 'Spouse birthday cannot be in the future.',
        ];
    }
}
