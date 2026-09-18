<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReimbursementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'or_no' => ['nullable', 'string', 'max:100'],
            'or_date' => ['required', 'date'],
            'or_amount' => ['required', 'numeric', 'min:0.01'],
            'hospital_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
