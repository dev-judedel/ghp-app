<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasEmailRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
{
    use HasEmailRule;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
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
            // No 'deduction_start_date' rule — it's always computed
            // server-side from start_date (see MemberController::store()
            // and BenefitAccrualService::resolveDeductionStartDate()),
            // never accepted directly from the request.
            'ghp_amount' => ['required', 'numeric', 'min:0'],
            'division_id' => ['nullable', 'exists:divisions,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'old_code' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A member with this code already exists.',
        ];
    }
}
