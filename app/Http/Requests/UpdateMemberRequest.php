<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasEmailRule;
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
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A member with this code already exists.',
        ];
    }
}
