<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAmountAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'new_amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
            'request_reference' => ['nullable', 'string', 'max:255'],
            'requested_at' => ['nullable', 'date'],
        ];
    }
}
