<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBenefitPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after:from_date'],
            'ghp_amount' => ['required', 'numeric', 'min:0'],
            'ghp_used' => ['required', 'numeric', 'min:0'],
            'ghp_available' => ['required', 'numeric', 'min:0'],
        ];
    }
}
