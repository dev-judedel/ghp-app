<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasEmailRule;
use App\Http\Requests\Concerns\ValidatesCoverageSetup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
{
    use HasEmailRule;
    use ValidatesCoverageSetup;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return $this->coverageRules() + [
            // Optional: if the admin leaves this blank, MemberController::store()
            // generates one (Member::generateUniqueCode(), format ALSC-######).
            // If they type one in, it's used as-is once validated here.
            'code' => ['nullable', 'string', 'max:50', 'unique:members,code'],
            'email' => ['required', 'max:255', $this->emailRule(), 'unique:members,email'],
            'member_type' => ['required', 'in:0,1'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'birthdate' => ['nullable', 'date'],
            'civil_status' => ['nullable', 'in:0,1'],
            'apply_date' => ['required', 'date'],
            'start_date' => ['required', 'date'],
            // deduction_start_date ("Start Date") is now an optional, editable
            // input (see coverageRules()). When left blank it is still computed
            // server-side from start_date — the 1st of the following month —
            // exactly as before (MemberController::store() and
            // BenefitAccrualService::resolveDeductionStartDate()).
            'ghp_amount' => ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'division_id' => ['nullable', 'exists:divisions,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'old_code' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return $this->coverageMessages() + [
            'code.unique' => 'A member with this code already exists.',
            'ghp_amount.required' => 'GHP amount is required.',
            'ghp_amount.numeric' => 'GHP amount must be a valid amount.',
            'ghp_amount.min' => 'GHP amount cannot be negative.',
            'ghp_amount.max' => 'GHP amount is too large.',
            'ghp_amount.decimal' => 'GHP amount can have at most 2 decimal places.',
        ];
    }
}
