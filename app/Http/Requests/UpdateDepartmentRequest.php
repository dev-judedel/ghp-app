<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $department = $this->route('department');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($department?->id)],
            // See StoreDepartmentRequest — free-text name, resolved/created
            // by DepartmentController, not a division_id select anymore.
            'division' => ['nullable', 'string', 'max:255'],
        ];
    }
}
