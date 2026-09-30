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
            // A valid positive amount, based on the actual expense/documents.
            // There is deliberately NO business maximum and no comparison with
            // the member's available GHP (see ReimbursementController::
            // assertHasAvailableBalance()). The `max` below is only the size
            // of the database column (decimal(12,2)), so a mistyped huge
            // number gets a clear message instead of a database error.
            'or_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'hospital_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'or_amount.required' => 'Enter the reimbursement amount.',
            'or_amount.numeric' => 'The reimbursement amount must be a valid number.',
            'or_amount.min' => 'The reimbursement amount must be greater than zero.',
            'or_amount.max' => 'That amount is too large to record.',
            'or_amount.decimal' => 'The reimbursement amount can have at most 2 decimal places.',
        ];
    }
}
