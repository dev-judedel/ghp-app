<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Same rules as StoreDivisionRequest: no uniqueness check, so renaming a
     * division to a name another division already uses is allowed.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'member_type' => ['required', 'in:0,1'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Division name is required.',
            'member_type.required' => 'Please choose Employee or Agent.',
            'member_type.in' => 'Please choose Employee or Agent.',
        ];
    }
}
