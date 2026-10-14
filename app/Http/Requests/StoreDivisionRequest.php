<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Division names are deliberately NOT unique (task.md §2.36): two
     * divisions may share a name and are told apart by their own id. The name
     * is still required, and the member type must be Employee (0) or Agent (1).
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
