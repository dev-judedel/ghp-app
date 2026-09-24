<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidBenefitPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Field is named 'void_reason' rather than 'reason' on purpose — the
     * reimbursement void modal on the same page (members/show.blade.php)
     * already validates a field literally named 'reason', and the two
     * modals' error-reopening logic distinguishes itself by which field
     * name is present in the error bag. Sharing a name would make a
     * validation failure on either modal re-open both of them.
     */
    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
