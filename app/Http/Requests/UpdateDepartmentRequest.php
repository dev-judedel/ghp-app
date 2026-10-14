<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Same rules as StoreDepartmentRequest: no uniqueness check, so renaming a
     * department to a name another department already uses is allowed
     * (task.md §2.36). The record being edited is identified by its id in the route.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'division' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Department name is required.',
            'division_id.exists' => 'The selected division no longer exists.',
        ];
    }
}
