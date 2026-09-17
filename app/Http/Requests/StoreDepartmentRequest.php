<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:departments,name'],
            // Free-text division name, not a division_id select anymore —
            // DepartmentController resolves this to an existing Division by
            // name (case-insensitive) or creates one if it doesn't exist yet.
            'division' => ['nullable', 'string', 'max:255'],
        ];
    }
}
