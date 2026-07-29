<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $member = $this->route('member');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('members', 'code')->ignore($member?->id)],
            'member_type' => ['required', 'in:0,1'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'birthdate' => ['nullable', 'date'],
            'civil_status' => ['nullable', 'in:0,1'],
            'apply_date' => ['nullable', 'date'],
            'deduction_start_date' => ['nullable', 'date'],
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
