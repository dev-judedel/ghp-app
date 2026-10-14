<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Department names are deliberately NOT unique (task.md §2.36): two
     * departments may share a name and are told apart by their own id. The
     * name is still required.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // The Division is chosen by its ID (a <select>), so two divisions that
            // share a name can't be confused. Optional: blank = no division.
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            // Legacy free-text division NAME, kept only so older callers keep working
            // (see DepartmentController::resolveDivisionId()). division_id wins when both are sent.
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
