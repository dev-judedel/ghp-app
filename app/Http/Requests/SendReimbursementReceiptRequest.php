<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendReimbursementReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Deliberately NO recipient field: the receipt always goes to the email
     * saved on the member's record (see BenefitPeriodController::sendReceipt()).
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
